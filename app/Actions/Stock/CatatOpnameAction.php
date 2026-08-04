<?php

namespace App\Actions\Stock;

use App\Enums\AlasanOpname;
use App\Enums\StockMovementType;
use App\Models\Outlet;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Mencatat hasil hitung fisik (stok opname) satu lembar.
 *
 * Yang dijaga di sini, dan alasannya:
 *
 * 1. **Selisih dihitung terhadap saldo SAAT SIMPAN, di dalam lock** — bukan terhadap
 *    angka sistem yang terbaca di layar saat lembar dibuka. Menghitung 80 barang butuh
 *    setengah jam, dan kasir tetap berjualan selama itu. Kalau selisihnya dihitung dari
 *    angka layar, setiap penjualan yang terjadi selama penghitungan akan dibatalkan oleh
 *    opname — stok "diperbaiki" ke angka yang sudah kedaluwarsa, dan barang yang benar
 *    benar terjual kembali muncul di catatan.
 *
 * 2. **Satu transaksi PER BARIS, bukan satu untuk seluruh lembar.** Satu baris yang gagal
 *    (barang dihapus orang lain di tengah penghitungan, alasan tidak sah) tidak boleh
 *    membatalkan 120 baris yang sudah benar. Pemilik yang kehilangan setengah jam
 *    penghitungan karena satu baris tidak akan mencoba lagi.
 *
 * 3. **Kalau saldo saat simpan berbeda dari angka yang terbaca di layar, catatan mutasi
 *    memuat KEDUA angka.** Tanpa itu, kartu stok memperlihatkan selisih yang tidak bisa
 *    dijelaskan siapa pun, dan orang yang menghitung akan disalahkan untuk pergerakan
 *    yang terjadi setelah ia menghitung.
 *
 * Aksi ini TIDAK mengubah saldo sendiri. Satu-satunya jalur tetap AdjustStockAction,
 * supaya tiap perubahan angka meninggalkan satu baris kartu stok.
 */
class CatatOpnameAction
{
    public function __construct(
        private SiapkanBarisStokAction $siapkanStok,
        private AdjustStockAction $adjust,
    ) {}

    /**
     * @param  array<int, array{product_id?: ?string, raw_material_id?: ?string, fisik?: mixed, alasan?: AlasanOpname|string|null, catatan?: ?string, sistem_saat_dibuka?: mixed}>  $baris
     * @return array{dihitung: int, mutasi: int, gagal: array<int, array{product_id: ?string, raw_material_id: ?string, pesan: string}>}
     */
    public function execute(Outlet $outlet, User $oleh, array $baris): array
    {
        $ringkasan = ['dihitung' => 0, 'mutasi' => 0, 'gagal' => []];

        foreach ($baris as $item) {
            $fisik = $item['fisik'] ?? null;

            // Fisik yang DIBIARKAN KOSONG bukan nol — barangnya belum dihitung. Nol
            // adalah pernyataan "rak ini kosong", dan itu justru selisih terbesar yang
            // bisa ada. Menganggap kosong sebagai nol akan menghapus seluruh stok
            // barang yang tidak sempat dihitung.
            if (! $this->diisi($fisik)) {
                continue;
            }

            $productId = $item['product_id'] ?? null;
            $rawMaterialId = $item['raw_material_id'] ?? null;

            try {
                if (! is_numeric($fisik)) {
                    throw new InvalidArgumentException('Jumlah fisik harus berupa angka.');
                }

                $adaMutasi = $this->catatSatuBaris($outlet, $oleh, $item, (float) $fisik, $productId, $rawMaterialId);

                $ringkasan['dihitung']++;

                if ($adaMutasi) {
                    $ringkasan['mutasi']++;
                }
            } catch (Throwable $galat) {
                $ringkasan['gagal'][] = [
                    'product_id' => $productId,
                    'raw_material_id' => $rawMaterialId,
                    'pesan' => $galat->getMessage(),
                ];
            }
        }

        return $ringkasan;
    }

    /**
     * Satu baris = satu transaksi sendiri.
     *
     * @return bool true kalau baris ini menghasilkan mutasi (selisihnya bukan nol)
     */
    private function catatSatuBaris(
        Outlet $outlet,
        User $oleh,
        array $item,
        float $fisik,
        ?string $productId,
        ?string $rawMaterialId,
    ): bool {
        if (($productId === null) === ($rawMaterialId === null)) {
            throw new InvalidArgumentException('Satu baris opname harus menunjuk tepat satu produk ATAU satu bahan baku.');
        }

        if ($fisik < 0) {
            // Rak tidak bisa berisi minus tiga. Angka minus di kolom fisik selalu salah
            // ketik, dan menerimanya berarti mengarang selisih dua kali lipat.
            throw new InvalidArgumentException('Jumlah fisik tidak boleh negatif.');
        }

        $alasan = $this->alasanDari($item['alasan'] ?? null);
        $catatanManusia = $this->teksBersih($item['catatan'] ?? null);
        $sistemDibuka = is_numeric($item['sistem_saat_dibuka'] ?? null)
            ? (float) $item['sistem_saat_dibuka']
            : null;

        return DB::transaction(function () use ($outlet, $oleh, $fisik, $productId, $rawMaterialId, $alasan, $catatanManusia, $sistemDibuka) {
            $stok = $this->siapkanStok->execute(
                $outlet->tenant_id,
                $outlet->getKey(),
                $productId,
                $rawMaterialId,
            );

            // Saldo dibaca DI DALAM lock, setelah barisnya dipastikan ada. Inilah angka
            // yang menjadi dasar selisih — bukan angka yang terbaca di layar.
            $terkunci = Stock::query()->whereKey($stok->getKey())->lockForUpdate()->first() ?? $stok;

            $sistem = round((float) $terkunci->jumlah_saat_ini, 3);
            $selisih = round($fisik - $sistem, 3);

            if (abs($selisih) > 0.0005) {
                if ($alasan === null) {
                    throw new InvalidArgumentException('Selisih stok wajib punya alasan.');
                }

                if ($alasan->butuhCatatan() && $catatanManusia === null) {
                    throw new InvalidArgumentException('Alasan "'.$alasan->label().'" wajib disertai catatan.');
                }

                $this->adjust->execute(
                    $terkunci,
                    StockMovementType::Opname,
                    $selisih,
                    // Referensi NULL memang benar untuk opname: tidak ada dokumen sumber.
                    // Yang menjelaskan mutasinya adalah kolom alasan + catatan.
                    referensi: null,
                    olehUserId: $oleh->getKey(),
                    catatan: $this->susunCatatan($fisik, $sistem, $selisih, $catatanManusia, $sistemDibuka),
                    alasan: $alasan,
                );
            }

            /*
             * Ditandai sudah dihitung walaupun selisihnya nol.
             *
             * Fisik yang PAS dengan sistem adalah hasil opname yang paling berharga —
             * itu bukti angkanya benar. Kalau baris seperti ini tidak ditandai, ia akan
             * terus tampil sebagai "belum pernah dihitung" dan pemiliknya menghitungnya
             * lagi minggu depan tanpa perlu.
             *
             * perlu_diperiksa dimatikan karena barangnya baru saja diperiksa; itu
             * definisi benderanya.
             */
            $terkunci->opname_terakhir_pada = now();
            $terkunci->perlu_diperiksa = false;
            $terkunci->save();

            return abs($selisih) > 0.0005;
        });
    }

    /**
     * Catatan mutasi opname.
     *
     * Fisik & sistem selalu ikut tercatat: enam bulan kemudian, "−3" saja tidak
     * memberitahu apa pun, sedangkan "fisik 7, sistem 10" masih bisa dibaca.
     */
    private function susunCatatan(
        float $fisik,
        float $sistem,
        float $selisih,
        ?string $catatanManusia,
        ?float $sistemDibuka,
    ): string {
        $bagian = [sprintf(
            'Opname: fisik %s, sistem %s, selisih %s.',
            $this->angka($fisik),
            $this->angka($sistem),
            ($selisih > 0 ? '+' : '').$this->angka($selisih),
        )];

        if ($sistemDibuka !== null && abs($sistemDibuka - $sistem) > 0.0005) {
            $bagian[] = sprintf(
                'Saldo bergerak selama penghitungan: layar menunjukkan %s, saldo saat disimpan %s. Selisih dihitung terhadap saldo saat disimpan.',
                $this->angka($sistemDibuka),
                $this->angka($sistem),
            );
        }

        if ($catatanManusia !== null) {
            $bagian[] = $catatanManusia;
        }

        return implode(' ', $bagian);
    }

    /** Angka gaya Indonesia tanpa nol ekor: 10, 7,5, 1.200. */
    private function angka(float $nilai): string
    {
        $teks = number_format($nilai, 3, ',', '.');

        return rtrim(rtrim($teks, '0'), ',');
    }

    private function alasanDari(AlasanOpname|string|null $alasan): ?AlasanOpname
    {
        if ($alasan instanceof AlasanOpname) {
            return $alasan;
        }

        if ($this->teksBersih($alasan) === null) {
            return null;
        }

        return AlasanOpname::tryFrom(trim($alasan))
            ?? throw new InvalidArgumentException('Alasan opname "'.$alasan.'" tidak dikenali.');
    }

    private function teksBersih(?string $teks): ?string
    {
        $bersih = trim((string) $teks);

        return $bersih === '' ? null : $bersih;
    }

    /** Nol dianggap TERISI; hanya null/string kosong yang berarti tidak dihitung. */
    private function diisi(mixed $nilai): bool
    {
        return $nilai !== null && $nilai !== '';
    }
}
