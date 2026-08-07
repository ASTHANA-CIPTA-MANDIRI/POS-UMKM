<?php

namespace App\Actions\Stok;

use App\Models\Bahan\RawMaterial;
use App\Models\Produk\Product;
use App\Models\Stok\Stock;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Menyusun daftar GABUNGAN barang yang punya stok di satu outlet: produk yang dilacak
 * DAN bahan baku, masing-masing dengan baris `stocks`-nya kalau ada.
 *
 * KENAPA satu tempat, bukan kueri di masing-masing halaman:
 *
 * Layar Stok dan lembar Opname harus melihat daftar yang SAMA PERSIS. Kalau keduanya
 * menyusun kueri sendiri, cepat atau lambat salah satunya lupa satu syarat — mis. hanya
 * lembar opname yang menyertakan barang yang belum punya baris `stocks` — dan hasilnya
 * adalah barang yang bisa dihitung tapi tidak pernah muncul di daftar stok, atau
 * sebaliknya. Barang yang hilang dari layar adalah barang yang datanya tidak akan pernah
 * diperbaiki.
 *
 * Aksi ini hanya MEMBACA. Tidak ada baris `stocks` yang dibuat di sini (itu tugas
 * SiapkanBarisStokAction saat opname disimpan) dan tidak ada saldo yang berubah.
 *
 * LEFT JOIN, bukan join biasa: barang yang belum pernah dicatat di outlet ini wajib
 * tetap muncul dengan sistem 0 — justru barang itulah yang paling butuh dihitung.
 */
class SusunBarisStokAction
{
    /**
     * @param  string  $jenis  semua|produk|bahan
     * @return Collection<int, array<string, mixed>>
     */
    public function execute(string $outletId, string $jenis = 'semua', string $cari = ''): Collection
    {
        $baris = collect();

        if ($jenis !== 'bahan') {
            $baris = $baris->concat($this->barisProduk($outletId, $cari));
        }

        if ($jenis !== 'produk') {
            $baris = $baris->concat($this->barisBahan($outletId, $cari));
        }

        return $baris->sortBy('nama', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * Produk yang dilacak dan BUKAN berbasis resep.
     *
     * Dua pengecualian yang sama dengan saringan "menipis" di layar produk:
     * - `lacak_stok` mati → jumlahnya memang tidak pernah dicatat (jasa, laundry kiloan
     *   yang tidak menyimpan barang), jadi menghitungnya fisik tidak punya arti;
     * - berbasis resep → yang berkurang saat terjual adalah BAHAN BAKUNYA. Baris stok
     *   produk jadinya tidak pernah bergerak, jadi ia akan tampak "habis" selamanya dan
     *   membanjiri daftar belanja dengan menu yang tidak pernah perlu dibeli.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function barisProduk(string $outletId, string $cari): Collection
    {
        return Product::query()
            ->select('products.*')
            ->addSelect([
                'stocks.id as stok_id',
                'stocks.jumlah_saat_ini as stok_jumlah',
                'stocks.stok_minimum as stok_ambang',
                'stocks.opname_terakhir_pada as stok_opname_pada',
                'stocks.perlu_diperiksa as stok_perlu_diperiksa',
            ])
            ->leftJoin('stocks', fn (JoinClause $join) => $join
                ->on('stocks.product_id', '=', 'products.id')
                ->where('stocks.outlet_id', '=', $outletId))
            ->where('products.lacak_stok', true)
            ->whereDoesntHave('recipeItems')
            ->when($cari !== '', fn ($q) => $q->where(function ($w) use ($cari) {
                $w->where('products.nama_produk', 'like', '%'.$cari.'%')
                    ->orWhere('products.sku', 'like', '%'.$cari.'%')
                    ->orWhere('products.barcode', 'like', '%'.$cari.'%');
            }))
            ->get()
            ->map(function (Product $produk): array {
                $stok = $this->stokSementara($produk->stok_jumlah, $produk->stok_ambang, $produk);

                return $this->baris(
                    kunci: $produk->getKey(),
                    jenis: 'produk',
                    nama: $produk->nama_produk,
                    kode: $produk->sku,
                    aktif: (bool) $produk->is_active,
                    stok: $stok,
                    sumber: $produk,
                    satuan: $produk->satuan?->value,
                    satuanDasar: ($produk->satuan_dasar ?? $produk->satuan)?->value,
                    isiPerSatuan: $produk->isi_per_satuan !== null ? (float) $produk->isi_per_satuan : null,
                    productId: $produk->getKey(),
                    rawMaterialId: null,
                );
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function barisBahan(string $outletId, string $cari): Collection
    {
        return RawMaterial::query()
            ->select('raw_materials.*')
            ->addSelect([
                'stocks.id as stok_id',
                'stocks.jumlah_saat_ini as stok_jumlah',
                'stocks.stok_minimum as stok_ambang',
                'stocks.opname_terakhir_pada as stok_opname_pada',
                'stocks.perlu_diperiksa as stok_perlu_diperiksa',
            ])
            ->leftJoin('stocks', fn (JoinClause $join) => $join
                ->on('stocks.raw_material_id', '=', 'raw_materials.id')
                ->where('stocks.outlet_id', '=', $outletId))
            ->when($cari !== '', fn ($q) => $q->where('raw_materials.nama', 'like', '%'.$cari.'%'))
            ->get()
            ->map(function (RawMaterial $bahan): array {
                // Bahan baku tidak punya konversi satuan beli → satuan dasar (belum ada
                // kolomnya), jadi kekurangannya selalu dinyatakan dalam satuannya sendiri.
                $stok = $this->stokSementara($bahan->stok_jumlah, $bahan->stok_ambang, null);

                return $this->baris(
                    kunci: $bahan->getKey(),
                    jenis: 'bahan',
                    nama: $bahan->nama,
                    kode: null,
                    aktif: (bool) $bahan->is_active,
                    stok: $stok,
                    sumber: $bahan,
                    satuan: $bahan->satuan?->value,
                    satuanDasar: $bahan->satuan?->value,
                    isiPerSatuan: null,
                    productId: null,
                    rawMaterialId: $bahan->getKey(),
                );
            });
    }

    /**
     * Aturan "minus / habis / menipis / aman" dan hitungan kekurangan TIDAK ditulis ulang
     * di sini. Nilai hasil join dititipkan ke satu Stock sementara dan Stock-lah yang
     * memutuskan — pola yang sama dengan Product::statusStokTerjoin(). Dua tempat yang
     * memutuskan hal yang sama berarti dasbor dan daftar akan berbeda suatu hari, dan itu
     * terbaca sebagai cacat oleh orang yang melihatnya.
     *
     * `?? 0` di sini WAJIB dan bukan kelalaian: kekurangan() dan
     * kekuranganDalamSatuanBeli() mengerjakan aritmetika atas kedua nilai ini. Barang
     * yang belum punya baris `stocks` dibedakan di baris(), lewat `stok_id === null` —
     * BUKAN dengan menaruh null di sini, karena `(float) null` tetap 0.0 dan statusnya
     * tetap keluar 'habis'.
     */
    private function stokSementara(mixed $jumlah, mixed $ambang, ?Product $produk): Stock
    {
        $stok = new Stock([
            'jumlah_saat_ini' => $jumlah ?? 0,
            'stok_minimum' => $ambang ?? 0,
        ]);

        // Dipasang manual supaya kekuranganDalamSatuanBeli() tidak menembakkan query
        // produk per baris (dan supaya bahan baku tidak mencari produk yang tidak ada).
        $stok->setRelation('product', $produk);

        return $stok;
    }

    /** @return array<string, mixed> */
    private function baris(
        string $kunci,
        string $jenis,
        string $nama,
        ?string $kode,
        bool $aktif,
        Stock $stok,
        Product|RawMaterial $sumber,
        ?string $satuan,
        ?string $satuanDasar,
        ?float $isiPerSatuan,
        ?string $productId,
        ?string $rawMaterialId,
    ): array {
        return [
            'kunci' => $kunci,
            'jenis' => $jenis,
            'nama' => $nama,
            'kode' => $kode,
            'aktif' => $aktif,
            'product_id' => $productId,
            'raw_material_id' => $rawMaterialId,
            'stock_id' => $sumber->stok_id,
            // Barang tanpa baris `stocks` bukan barang bersaldo nol yang sudah dihitung —
            // ia belum pernah dicatat sama sekali. Bedanya wajib bisa dibaca di layar,
            // supaya "0" tidak dibaca sebagai "sudah dipastikan habis".
            'punya_baris' => $sumber->stok_id !== null,
            'sistem' => (float) ($sumber->stok_jumlah ?? 0),
            'minimum' => (float) ($sumber->stok_ambang ?? 0),
            'satuan' => $satuan,
            'satuan_dasar' => $satuanDasar,
            'isi_per_satuan' => $isiPerSatuan,
            // Status mengikuti ada-tidaknya ANGKA, bukan besarnya angka. Tanpa baris
            // `stocks` tidak ada angka yang bisa ditanyakan statusnya, jadi memanggil
            // Stock::statusStok() di sini berarti mengarang: `0` masuk, `habis` keluar,
            // dan warung yang baru memasukkan 300 barang melihat 300 lencana merah
            // "Habis". Hari kedua pemiliknya belajar mengabaikan merah, dan hari barang
            // pertama benar-benar kosong, merah sudah tidak berarti apa-apa lagi.
            //
            // Ini cabang di SINI, bukan di stokSementara() maupun Stock::statusStok():
            // - stokSementara() butuh `?? 0` supaya kekurangan() tidak pecah, dan
            //   `jumlah = null` di sana tetap dibaca `(float) null === 0.0` → 'habis';
            // - Stock::statusStok() menjawab "diberi sebuah angka, apa statusnya" —
            //   `0` dengan baris nyata memang 'habis', dan itu benar.
            // Presedennya Product::statusStokTerjoin(), yang sudah membedakan hal sama.
            //
            // Nilainya string bernama, BUKAN null: `match` di Blade berakhir
            // `default => 'Aman'`, jadi null akan menampilkan 300 barang sebagai hijau
            // "Aman" — lebih merugikan daripada merah, karena merah setidaknya membuat
            // orang memeriksa.
            'status' => $sumber->stok_id === null ? 'belum_dihitung' : $stok->statusStok(),
            'kekurangan' => $stok->kekurangan(),
            'beli' => $stok->kekuranganDalamSatuanBeli(),
            'opname_terakhir_pada' => $sumber->stok_opname_pada !== null
                ? Carbon::parse($sumber->stok_opname_pada)
                : null,
            'perlu_diperiksa' => (bool) ($sumber->stok_perlu_diperiksa ?? false),
        ];
    }
}
