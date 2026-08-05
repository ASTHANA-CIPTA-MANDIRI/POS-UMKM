<?php

namespace App\Actions\Purchase;

use App\Actions\Stock\AdjustStockAction;
use App\Actions\Stock\SiapkanBarisStokAction;
use App\Enums\DocumentStatus;
use App\Enums\StockMovementType;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\RawMaterial;
use App\Models\Scopes\TenantScope;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Mencatat satu nota pembelian: barang masuk, harga beli diperbarui, total dihitung.
 *
 * Urutan langkahnya bukan karangan baru — ini urutan yang sudah dijalankan
 * KelontongSeeder & WartegSeeder (Supplier → PO Diterima → item → stok masuk → total),
 * dipindahkan ke satu tempat supaya layar dan data demo memakai jalur yang sama.
 *
 * KEPUTUSAN YANG MENENTUKAN ANGKA, dan kenapa:
 *
 * 1. **Tidak ada alur draf.** Nota disimpan berarti barangnya SUDAH datang: status
 *    langsung Diterima, `diterima_pada` = sekarang, stok langsung bertambah. Pemilik
 *    warung mencatat sesudah menurunkan barang dari motor, bukan sebelum memesan; status
 *    "draft" yang tidak punya tombol lanjutan hanya menahan stok yang sudah ada di rak.
 *
 * 2. **Harga beli disimpan PER SATUAN DASAR** (`subtotal ÷ qty satuan dasar`), bukan per
 *    satuan beli. 2 dus @58.000 isi 12 = 4.833,33 per pcs, bukan 58.000 per pcs. Ini
 *    risiko terbesar fitur ini: menyimpan 58.000 tidak memunculkan satu pun galat, tapi
 *    nilai persediaan di layar stok melar 12 kali lipat dan setiap keputusan harga jual
 *    yang dibuat dari angka itu salah.
 *
 * 3. **Baris berharga 0 TIDAK menimpa harga beli.** Bonus grosir ("beli 10 dapat 1")
 *    dicatat dengan harga 0, dan menimpakan nol berarti menghapus harga yang benar —
 *    barangnya lalu terbaca tidak bernilai di seluruh laporan persediaan.
 *
 * 4. **Rata-rata bergerak DITOLAK.** Ia butuh saldo sebagai pembagi, sedangkan di POS ini
 *    saldo boleh minus dan boleh belum ada sama sekali. Rata-rata atas saldo −3
 *    menghasilkan harga negatif tanpa satu pun galat. Harga TERAKHIR yang menang.
 *
 * 5. **Diskon & ongkos kirim tidak dibagi ke harga barang.** Ongkir bukan nilai barang;
 *    membebankannya ke harga beli membuat nilai persediaan naik karena biaya angkut.
 *
 * 6. **Tidak menyentuh `cash_movements`.** Kolom itu menuntut `cash_session_id`, sedangkan
 *    belanja ke grosir terjadi di luar shift kasir — menempelkannya ke sembarang sesi
 *    membuat rekonsiliasi laci tidak pernah cocok lagi.
 *
 * 7. **Tidak menyentuh `opname_terakhir_pada` maupun `perlu_diperiksa`.** Barang masuk
 *    bukan barang yang dihitung; mematikan bendera "perlu diperiksa" karena ada barang
 *    datang berarti menghapus pengingat memeriksa selisih yang belum pernah diperiksa.
 */
class CatatPembelianAction
{
    /**
     * Berapa kali penyimpanan diulang saat nomor notanya bertabrakan.
     *
     * Ada `unique(['tenant_id','nomor_po'])`, dan nomor dihitung dari nomor terbesar yang
     * ada — dua perangkat yang menyimpan pada detik yang sama sama-sama membaca angka yang
     * sama. Tanpa coba-ulang, yang kalah mendapat 500 di depan pemilik yang baru saja
     * mengetik 12 baris, dan seluruh isiannya hilang.
     */
    private const MAKS_PERCOBAAN = 5;

    public function __construct(
        private SiapkanBarisStokAction $siapkanStok,
        private AdjustStockAction $adjust,
    ) {}

    /**
     * @param  array{beli_dari?: ?string, tanggal?: mixed, diskon?: mixed, ongkos_kirim?: mixed, catatan?: ?string, baris: array<int, array{product_id?: ?string, raw_material_id?: ?string, qty_beli?: mixed, harga_satuan?: mixed}>}  $muatan
     */
    public function execute(Outlet $outlet, User $oleh, array $muatan): PurchaseOrder
    {
        $baris = array_values($muatan['baris'] ?? []);

        if ($baris === []) {
            // Nota kosong bukan nota. Kalau dibiarkan lolos, daftar pembelian terisi
            // baris-baris tanpa barang yang tidak bisa dibedakan dari nota yang isinya
            // gagal tersimpan.
            throw new InvalidArgumentException('Nota pembelian harus berisi minimal satu barang.');
        }

        $diskon = $this->uang($muatan['diskon'] ?? 0, 'Diskon');
        $ongkir = $this->uang($muatan['ongkos_kirim'] ?? 0, 'Ongkos kirim');
        $tanggal = $this->tanggal($muatan['tanggal'] ?? null);

        for ($percobaan = 1; ; $percobaan++) {
            try {
                return DB::transaction(fn (): PurchaseOrder => $this->simpan(
                    $outlet,
                    $oleh,
                    $baris,
                    $tanggal,
                    $diskon,
                    $ongkir,
                    $this->teksBersih($muatan['beli_dari'] ?? null),
                    $this->teksBersih($muatan['catatan'] ?? null),
                ));
            } catch (UniqueConstraintViolationException $bentrok) {
                // Nomor notanya keburu dipakai perangkat lain di celah antara "hitung
                // nomor" dan "simpan". Transaksinya sudah digulung balik, jadi mengulang
                // dari awal aman: nomor dihitung ulang dan tidak ada baris setengah jadi.
                if ($percobaan >= self::MAKS_PERCOBAAN) {
                    throw $bentrok;
                }
            }
        }
    }

    /**
     * Seluruh nota dalam SATU transaksi.
     *
     * Berbeda dari opname (satu transaksi per baris) dan itu disengaja: lembar opname
     * adalah 120 pengamatan yang berdiri sendiri, sedangkan nota adalah satu dokumen.
     * Nota yang tersimpan separuh — tiga barang masuk, dua tidak, totalnya salah — lebih
     * buruk daripada nota yang gagal seluruhnya, karena tidak ada yang tahu bagian mana
     * yang hilang dan mengulanginya akan menggandakan tiga baris pertama.
     *
     * @param  array<int, array<string, mixed>>  $baris
     */
    private function simpan(
        Outlet $outlet,
        User $oleh,
        array $baris,
        Carbon $tanggal,
        float $diskon,
        float $ongkir,
        ?string $beliDari,
        ?string $catatan,
    ): PurchaseOrder {
        $tenantId = $outlet->tenant_id;

        $po = new PurchaseOrder([
            'outlet_id' => $outlet->getKey(),
            'supplier_id' => $this->supplierUntuk($tenantId, $beliDari),
            // Dihitung DI DALAM transaksi: di luar, jendela antara menghitung dan menulis
            // selebar seluruh sisa penyimpanan.
            'nomor_po' => $this->nomorNota($tenantId, $tanggal),
            'tanggal' => $tanggal->toDateString(),
            'status' => DocumentStatus::Diterima,
            'diterima_pada' => now(),
            'diskon' => $diskon,
            'ongkos_kirim' => $ongkir,
            'catatan' => $catatan,
            'dibuat_oleh' => $oleh->getKey(),
        ]);

        // tenant_id tidak pernah fillable; diisi eksplisit supaya aksi ini tetap benar
        // walau dipanggil dari jalur tanpa TenantContext (perintah artisan, seeder).
        $po->tenant_id = $tenantId;
        $po->save();

        $subtotalNota = 0.0;

        foreach ($baris as $satu) {
            $subtotalNota += $this->catatBaris($po, $outlet, $oleh, $satu);
        }

        // total = subtotal − diskon + ongkir. Diskon & ongkir TIDAK dibagi ke harga
        // barang; keduanya hanya menggeser uang yang keluar, bukan nilai barangnya.
        $po->total = round($subtotalNota - $diskon + $ongkir, 2);
        $po->save();

        return $po;
    }

    /**
     * Satu baris nota: item tersimpan, stok bertambah, harga beli master diperbarui.
     *
     * @param  array<string, mixed>  $satu
     * @return float subtotal baris ini
     */
    private function catatBaris(PurchaseOrder $po, Outlet $outlet, User $oleh, array $satu): float
    {
        $productId = $satu['product_id'] ?? null;
        $rawMaterialId = $satu['raw_material_id'] ?? null;

        if (($productId === null) === ($rawMaterialId === null)) {
            throw new InvalidArgumentException('Satu baris pembelian harus menunjuk tepat satu produk ATAU satu bahan baku.');
        }

        $qtyBeli = $this->angka($satu['qty_beli'] ?? null, 'Jumlah beli');

        if ($qtyBeli <= 0) {
            // Nol berarti barisnya tidak diisi; negatif berarti salah ketik. Keduanya
            // tidak boleh menjadi mutasi stok — pembelian minus adalah retur, dan retur
            // punya jalur sendiri (pembatalan nota), bukan angka minus di nota masuk.
            throw new InvalidArgumentException('Jumlah beli harus lebih dari nol.');
        }

        $harga = $this->uang($satu['harga_satuan'] ?? 0, 'Harga beli');

        $barang = $productId !== null
            ? $this->produk($po->tenant_id, $productId)
            : $this->bahan($po->tenant_id, $rawMaterialId);

        // Konversi satuan HANYA lewat Product::keSatuanDasar() — satu-satunya tempat
        // faktor dus→pcs dipakai. Bahan baku belum punya kolom konversi, jadi angkanya
        // memang sudah dalam satuan pencatatannya sendiri.
        $qtyDasar = $barang instanceof Product
            ? $barang->keSatuanDasar($qtyBeli)
            : $qtyBeli;

        $subtotal = round($qtyBeli * $harga, 2);

        $item = new PurchaseOrderItem([
            'purchase_order_id' => $po->getKey(),
            'product_id' => $productId,
            'raw_material_id' => $rawMaterialId,
            // qty = satuan DASAR (yang masuk kartu stok); qty_beli = yang diketik pemilik.
            'qty' => $qtyDasar,
            'qty_beli' => $qtyBeli,
            // POTRET, bukan acuan hidup: master boleh berubah, nota lama tetap terbaca
            // dengan angka yang berlaku saat ia ditulis.
            'satuan_beli' => $barang->satuan?->value,
            'isi_per_satuan_beli' => $barang instanceof Product && $barang->isi_per_satuan !== null
                ? (float) $barang->isi_per_satuan
                : null,
            // Nota disimpan berarti barangnya sudah datang: yang dipesan = yang diterima.
            'qty_diterima' => $qtyDasar,
            'harga_satuan' => $harga,
            'subtotal' => $subtotal,
        ]);

        $item->tenant_id = $po->tenant_id;
        $item->save();

        $stok = $this->siapkanStok->execute(
            $po->tenant_id,
            $outlet->getKey(),
            $productId,
            $rawMaterialId,
        );

        $this->adjust->execute(
            $stok,
            StockMovementType::Masuk,
            $qtyDasar,
            referensi: $po,
            olehUserId: $oleh->getKey(),
            catatan: 'Pembelian '.$po->nomor_po.($po->supplier_id !== null ? ' — '.($po->supplier?->nama ?? '') : ''),
            // AlasanOpname tidak berlaku di sini: tipenya (Masuk) sudah menjelaskan
            // sebabnya, dan mengisi kolom alasan membuat laporan selisih penuh baris
            // yang bukan selisih.
            alasan: null,
        );

        $this->perbaruiHargaBeli($barang, $subtotal, $qtyDasar);

        return $subtotal;
    }

    /**
     * Harga beli master ditimpa dengan harga nota ini, PER SATUAN DASAR.
     *
     * Baris berharga nol dilewati: hadiah/bonus grosir tidak menyatakan apa pun tentang
     * harga barangnya, dan menimpakan nol menghapus harga yang benar.
     */
    private function perbaruiHargaBeli(Product|RawMaterial $barang, float $subtotal, float $qtyDasar): void
    {
        if ($subtotal <= 0 || $qtyDasar <= 0) {
            return;
        }

        $perSatuanDasar = round($subtotal / $qtyDasar, 2);

        if ($barang instanceof Product) {
            $barang->harga_beli = $perSatuanDasar;
        } else {
            $barang->harga_beli_terakhir = $perSatuanDasar;
        }

        $barang->save();
    }

    /**
     * Nomor nota `PB-YYYYMMDD-001`, berurut per tenant per tanggal.
     *
     * Awalannya PB (bukan PO) karena layar ini mencatat NOTA BELANJA yang barangnya sudah
     * datang, bukan pesanan pembelian — dan data demo yang sudah ada memakai PO-…, jadi
     * dua awalan yang berbeda menjaga nomor lama tidak pernah ikut terhitung.
     *
     * withoutGlobalScopes() melepas TenantScope (tenant sudah disaring manual) DAN
     * SoftDeletingScope: nomor milik nota yang dihapus tidak boleh dipakai ulang, karena
     * unique index tetap menghitungnya dan nomor kembar membuat dua nota berbeda menunjuk
     * satu identitas di mata pemiliknya.
     */
    private function nomorNota(string $tenantId, Carbon $tanggal): string
    {
        $awalan = 'PB-'.$tanggal->format('Ymd');

        $dasar = fn () => PurchaseOrder::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId);

        $terakhir = $dasar()->where('nomor_po', 'like', $awalan.'-%')->max('nomor_po');
        $nomor = $terakhir === null
            ? 1
            : ((int) substr((string) $terakhir, strlen($awalan) + 1)) + 1;

        // Diperiksa satu per satu sampai bebas: nomor bisa melompat kalau ada nota yang
        // nomornya pernah diisi tangan dan tidak berbentuk angka.
        do {
            $kode = $awalan.'-'.str_pad((string) $nomor, 3, '0', STR_PAD_LEFT);
            $nomor++;
        } while ($dasar()->where('nomor_po', $kode)->exists());

        return $kode;
    }

    /**
     * "Beli dari" adalah teks bebas; tabel suppliers tetap dipakai sebagai daftar nama.
     *
     * Tidak ada layar kelola supplier, dan itu disengaja: pemilik warung tidak akan
     * mengisi formulir master supplier sebelum mencatat belanja. Nama yang sudah pernah
     * dipakai ditawarkan sebagai saran; nama baru membuat barisnya sendiri. Dikosongkan =
     * sah, `supplier_id` null — belanja di pasar tidak selalu punya nama toko.
     *
     * Pencocokan tanpa peduli besar-kecil huruf, supaya "Grosir Amanah" dan "grosir
     * amanah" tidak menjadi dua pemasok yang berbeda di daftar saran.
     */
    private function supplierUntuk(string $tenantId, ?string $nama): ?string
    {
        if ($nama === null) {
            return null;
        }

        $ada = Supplier::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])
            ->first();

        if ($ada !== null) {
            return $ada->getKey();
        }

        $baru = new Supplier(['nama' => $nama]);
        $baru->tenant_id = $tenantId;
        $baru->save();

        return $baru->getKey();
    }

    /**
     * Barang dicari dengan filter tenant EKSPLISIT, bukan mengandalkan global scope.
     *
     * Aksi ini bisa dipanggil dari jalur tanpa TenantContext (seeder, perintah artisan),
     * dan di keadaan itu scope-nya tidak memfilter apa pun — id produk milik merchant lain
     * akan lolos dan stoknya bertambah di warung yang salah.
     */
    private function produk(string $tenantId, string $productId): Product
    {
        return Product::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereKey($productId)
            ->firstOrFail();
    }

    private function bahan(string $tenantId, string $rawMaterialId): RawMaterial
    {
        return RawMaterial::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereKey($rawMaterialId)
            ->firstOrFail();
    }

    private function tanggal(mixed $nilai): Carbon
    {
        if (blank($nilai)) {
            return Carbon::today();
        }

        return Carbon::parse($nilai)->startOfDay();
    }

    /** Uang: wajib angka, tidak boleh negatif. Nol sah (bonus grosir). */
    private function uang(mixed $nilai, string $label): float
    {
        if (blank($nilai)) {
            return 0.0;
        }

        if (! is_numeric($nilai)) {
            throw new InvalidArgumentException($label.' harus berupa angka.');
        }

        if ((float) $nilai < 0) {
            throw new InvalidArgumentException($label.' tidak boleh negatif.');
        }

        return round((float) $nilai, 2);
    }

    private function angka(mixed $nilai, string $label): float
    {
        if (! is_numeric($nilai)) {
            throw new InvalidArgumentException($label.' harus berupa angka.');
        }

        return (float) $nilai;
    }

    private function teksBersih(?string $teks): ?string
    {
        $bersih = trim((string) $teks);

        return $bersih === '' ? null : $bersih;
    }
}
