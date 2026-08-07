<?php

namespace Tests\Feature;

use App\Actions\Stock\ApplySaleToStockAction;
use App\Actions\Stock\SiapkanBarisStokAction;
use App\Enums\Satuan;
use App\Enums\StockMovementType;
use App\Enums\TransactionMode;
use App\Enums\UserRole;
use App\Models\Bahan\RawMaterial;
use App\Models\Kasir\Transaction;
use App\Models\Kasir\TransactionItem;
use App\Models\Produk\Product;
use App\Models\Stok\Stock;
use App\Models\Stok\StockMovement;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * CACAT YANG DIJAGA BERKAS INI: baris `stocks` bisa kembar.
 *
 * `SiapkanBarisStokAction` membaca dengan `first()` lalu memanggil `create` — dua langkah
 * terpisah, bukan satu operasi atomik. Dua tablet kasir yang menjual barang yang sama,
 * saat barang itu belum pernah punya baris stok di outlet tersebut, sama-sama membaca
 * "belum ada" lalu sama-sama menyisipkan. Akibatnya `LEFT JOIN` di layar stok menampilkan
 * barangnya KEMBAR dan saldonya terpecah dua: separuh mutasi mendarat di baris A, separuh
 * di baris B, sehingga tidak ada satu pun angka di layar yang benar.
 *
 * Dua lapis penjagaannya, dan dua-duanya diuji di sini karena masing-masing sendirian
 * tidak cukup:
 *
 * 1. **Unique index di basis data.** Ini satu-satunya yang benar-benar mencegah baris
 *    kedua tercipta; penjagaan di PHP selalu punya celah di antara baca dan tulis.
 * 2. **Aksinya menangani pelanggaran unique.** Tanpa ini, lapis pertama justru MENUKAR
 *    cacatnya: bukan lagi baris kembar, melainkan penjualan yang GAGAL karena stok —
 *    pelanggaran aturan 5 CLAUDE.md, dan penjualan yang gagal disinkronkan hilang dari
 *    catatan padahal barangnya sudah keluar dari rak dan uangnya sudah diterima.
 */
class StokBarisKembarTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Warung Kembar');
        $this->outlet = $this->buatOutlet($this->tenant, 'Cabang Kembar');
        $this->kasir = $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Kasir Kembar',
            'outlet_id' => $this->outlet->getKey(),
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    /* ── Lapis 1: skema ──────────────────────────────────────────────────── */

    /**
     * Penjaga skema — JANGAN hapus kedua unique index ini "karena tidak ada yang error".
     *
     * Keduanya sudah ada sejak create_stocks_table, tapi tidak ada satu pun uji yang
     * memeriksanya, jadi menghapusnya dari migrasi tidak akan membuat apa pun merah
     * sampai baris kembar muncul di basis data produksi. Di situ pun gejalanya bukan
     * galat, melainkan angka stok yang pelan-pelan tidak pernah cocok.
     *
     * Kedua kolom item-nya nullable dan saling eksklusif (satu baris entah produk entah
     * bahan baku). MySQL dan SQLite sama-sama menganggap dua NULL BERBEDA di dalam unique
     * index, jadi ratusan baris bahan baku (product_id NULL) di satu outlet tetap sah
     * menurut index (outlet_id, product_id) — keunikannya dijaga index pasangannya.
     */
    public function test_stocks_punya_unique_index_untuk_produk_dan_bahan_baku(): void
    {
        $unik = collect(Schema::getIndexes('stocks'))
            ->filter(fn (array $indeks) => $indeks['unique'] ?? false)
            ->pluck('columns')
            ->all();

        $this->assertContains(['outlet_id', 'product_id'], $unik,
            'tanpa index ini dua kasir bisa membuat dua baris stok untuk produk yang sama di satu outlet');
        $this->assertContains(['outlet_id', 'raw_material_id'], $unik,
            'bahan baku butuh penjagaan yang sama; index produk tidak menjangkaunya karena product_id-nya NULL');
    }

    /* ── Lapis 2: aksinya ────────────────────────────────────────────────── */

    public function test_dua_pemanggilan_untuk_produk_yang_sama_menghasilkan_satu_baris(): void
    {
        $produk = $this->buatProduk('Kopi Sachet');

        $pertama = $this->siapkan(productId: $produk->getKey());
        $kedua = $this->siapkan(productId: $produk->getKey());

        $this->assertSame($pertama->getKey(), $kedua->getKey(),
            'panggilan kedua harus memungut baris yang sudah ada, bukan membuat baris baru');
        $this->assertSame(1, $this->hitungBaris($produk->getKey(), null));
    }

    public function test_dua_pemanggilan_untuk_bahan_baku_yang_sama_menghasilkan_satu_baris(): void
    {
        $bahan = $this->buatBahan('Gula Pasir');

        $pertama = $this->siapkan(rawMaterialId: $bahan->getKey());
        $kedua = $this->siapkan(rawMaterialId: $bahan->getKey());

        $this->assertSame($pertama->getKey(), $kedua->getKey());
        $this->assertSame(1, $this->hitungBaris(null, $bahan->getKey()));
    }

    /**
     * Sisi sebaliknya, supaya index-nya tidak kebablasan: stok dicatat PER OUTLET.
     *
     * Kalau unique-nya keliru dipasang pada `product_id` saja, cabang kedua tidak akan
     * pernah bisa punya baris stok sendiri — penjualan di cabang itu akan mengurangi stok
     * cabang pertama, dan dua cabang berbagi satu angka yang tidak berlaku untuk keduanya.
     */
    public function test_produk_yang_sama_di_dua_outlet_tetap_dua_baris(): void
    {
        $produk = $this->buatProduk('Kopi Sachet');
        $outletKedua = $this->buatOutlet($this->tenant, 'Cabang Dua');

        $satu = $this->siapkan(productId: $produk->getKey());
        $dua = $this->siapkan(productId: $produk->getKey(), outletId: $outletKedua->getKey());

        $this->assertNotSame($satu->getKey(), $dua->getKey());
        $this->assertSame(2, Stock::query()->where('product_id', $produk->getKey())->count());
    }

    /* ── Balapan sungguhan ───────────────────────────────────────────────── */

    /**
     * SIMULASI BALAPAN: baris pesaing disisipkan TEPAT sebelum `create` dijalankan.
     *
     * Caranya lewat listener `Stock::creating` yang menyisipkan baris lain langsung dengan
     * query builder (bukan Eloquent, supaya tidak memicu listener-nya sendiri). Titik itu
     * persis jendela cacatnya: aksi sudah selesai membaca "belum ada", dan INSERT-nya belum
     * mendarat. Perangkat kasir kedua menyelesaikan insert-nya di celah itu.
     *
     * Yang benar-benar diuji di sini adalah penanganan pelanggaran unique. Uji mutasi
     * (membuang blok catch di SiapkanBarisStokAction) membuat uji ini merah dengan
     * "UNIQUE constraint failed: stocks.outlet_id, stocks.product_id" — bukan gagal
     * assertion, melainkan galat yang di lapangan berarti penjualan ditolak.
     */
    public function test_balapan_unique_tidak_melempar_dan_memungut_baris_yang_menang(): void
    {
        $produk = $this->buatProduk('Kopi Sachet');
        $idPesaing = $this->pasangPesaing(productId: $produk->getKey());

        $stok = $this->siapkan(productId: $produk->getKey());

        $this->assertSame($idPesaing, $stok->getKey(),
            'yang dikembalikan harus baris pemenang balapan, bukan baris baru yang gagal disimpan');
        $this->assertSame(1, $this->hitungBaris($produk->getKey(), null),
            'balapan tidak boleh menyisakan dua baris untuk satu barang di satu outlet');
    }

    /** Bahan baku lewat jalur yang sama, dan index-nya yang lain — jadi diuji terpisah. */
    public function test_balapan_unique_bahan_baku_juga_tidak_melempar(): void
    {
        $bahan = $this->buatBahan('Gula Pasir');
        $idPesaing = $this->pasangPesaing(rawMaterialId: $bahan->getKey());

        $stok = $this->siapkan(rawMaterialId: $bahan->getKey());

        $this->assertSame($idPesaing, $stok->getKey());
        $this->assertSame(1, $this->hitungBaris(null, $bahan->getKey()));
    }

    /**
     * Aturan 5 CLAUDE.md: penjualan JANGAN pernah diblokir karena stok.
     *
     * Uji di atas membuktikan aksinya tidak melempar; yang ini membuktikan akibatnya di
     * jalur yang sebenarnya. Penjualan atas produk yang belum punya baris stok, tepat saat
     * perangkat lain membuat barisnya duluan, tetap tercatat: mutasinya ada, saldonya
     * turun, dan barisnya tetap satu. Kalau pelanggaran unique-nya lolos ke atas,
     * ApplySaleToStockAction melempar, sinkronisasi menolak transaksinya, dan penjualan
     * yang sungguh terjadi hilang dari catatan.
     */
    public function test_penjualan_tetap_tercatat_walau_baris_stoknya_kalah_balapan(): void
    {
        $produk = $this->buatProduk('Kopi Sachet');
        $idPesaing = $this->pasangPesaing(productId: $produk->getKey(), saldoAwal: 10);

        $transaksi = $this->jual($produk, qty: 3, harga: 2000);

        $this->assertSame(1, $this->hitungBaris($produk->getKey(), null));

        $mutasi = StockMovement::query()->where('stock_id', $idPesaing)->sole();

        $this->assertEqualsWithDelta(-3.0, (float) $mutasi->jumlah, 0.001);
        $this->assertEqualsWithDelta(7.0, (float) $mutasi->saldo_sesudah, 0.001,
            'mutasinya harus mendarat di baris pemenang, bukan di baris kedua yang tidak jadi ada');
        $this->assertSame($transaksi->getKey(), $mutasi->referensi_id);
        $this->assertEqualsWithDelta(7.0, (float) Stock::query()->find($idPesaing)->jumlah_saat_ini, 0.001);
    }

    /* ── Isolasi tenant ──────────────────────────────────────────────────── */

    /**
     * Aksi ini dipanggil dari jalur sinkronisasi offline yang berjalan TANPA TenantContext
     * terpasang; di keadaan itu `shouldScope()` false, jadi global scope tidak memfilter
     * apa-apa. Tenant-nya karena itu datang dari argumen dan difilter sendiri di dalam
     * aksi — kalau tidak, pencarian baris bisa memungut baris milik merchant lain dan
     * penjualan satu warung akan mengurangi stok warung yang sama sekali lain.
     */
    public function test_baris_tenant_lain_tidak_pernah_terbawa(): void
    {
        $produk = $this->buatProduk('Kopi Sachet');
        $milikKita = $this->siapkan(productId: $produk->getKey());

        $tetangga = $this->buatTenant('Warung Tetangga');
        $outletTetangga = $this->buatOutlet($tetangga, 'Cabang Tetangga');
        $produkTetangga = $this->konteks()->forTenant($tetangga->getKey(), fn () => Product::create([
            'nama_produk' => 'Kopi Sachet',
            'harga_default' => 2000,
            'satuan' => Satuan::Pcs,
        ]));

        // Konteks sengaja dilepas: inilah keadaan jalur sinkronisasi.
        $this->konteks()->forget();

        $milikTetangga = app(SiapkanBarisStokAction::class)->execute(
            $tetangga->getKey(),
            $outletTetangga->getKey(),
            productId: $produkTetangga->getKey(),
        );

        $this->assertNotSame($milikKita->getKey(), $milikTetangga->getKey());
        $this->assertSame($tetangga->getKey(), $milikTetangga->tenant_id);
        $this->assertSame($this->tenant->getKey(), $milikKita->fresh()->tenant_id);
    }

    /* ── Migrasi penjamin ────────────────────────────────────────────────── */

    /**
     * Perbaikan data lama: dua baris kembar digabung tanpa satu pun mutasi kehilangan
     * induknya.
     *
     * Index-nya dilepas dulu supaya barisnya bisa dibuat — di basis data yang sehat
     * keadaan ini memang mustahil, dan itulah sebabnya jalur perbaikan ini tidak akan
     * pernah terlewati oleh uji lain. Yang ditiru adalah basis data yang sudah berjalan
     * dan pernah disentuh tangan; migrasi yang mati di tengah karena data lama lebih
     * buruk daripada tidak ada migrasi.
     *
     * Saldonya DIJUMLAHKAN, bukan dipilih salah satu: AdjustStockAction selalu menulis
     * `saldo + delta` dan baris baru selalu lahir dengan saldo 0, jadi 12 + (−5) = 7
     * adalah angka yang sama seperti kalau seluruh mutasi sejak awal mendarat di satu
     * baris. Memilih "yang mutasinya paling banyak" akan membuang pergerakan yang sungguh
     * terjadi di baris satunya.
     */
    public function test_migrasi_menggabungkan_baris_kembar_tanpa_menghilangkan_mutasi(): void
    {
        $produk = $this->buatProduk('Kopi Sachet');

        Schema::table('stocks', function ($tabel) {
            $tabel->dropUnique('stocks_outlet_id_product_id_unique');
        });

        $tua = $this->sisipkanBarisMentah($produk->getKey(), null, 12, '2026-08-01 08:00:00', [
            'stok_minimum' => 0,
            'tanggal_kadaluarsa' => '2026-12-31',
        ]);
        $muda = $this->sisipkanBarisMentah($produk->getKey(), null, -5, '2026-08-02 08:00:00', [
            'stok_minimum' => 25,
            'tanggal_kadaluarsa' => '2026-09-30',
        ]);

        $mutasiTua = $this->sisipkanMutasiMentah($tua, 12);
        $mutasiMuda = $this->sisipkanMutasiMentah($muda, -5);

        $this->jalankanMigrasiPenjamin();

        $tersisa = Stock::query()->where('product_id', $produk->getKey())->get();

        $this->assertCount(1, $tersisa, 'baris kembar harus tinggal satu');
        $this->assertSame($tua, $tersisa->first()->getKey(), 'induknya baris tertua — pilihan yang deterministik');
        $this->assertEqualsWithDelta(7.0, (float) $tersisa->first()->jumlah_saat_ini, 0.001,
            '12 + (−5): saldo tiap baris kembar adalah jumlah delta yang mendarat di baris itu');
        $this->assertEqualsWithDelta(25.0, (float) $tersisa->first()->stok_minimum, 0.001,
            'ambang yang pernah disetel pemilik tidak boleh hilang tertimpa nol "tidak dipantau"');
        $this->assertSame('2026-09-30', $tersisa->first()->tanggal_kadaluarsa->format('Y-m-d'),
            'yang terdekat: peringatan kadaluarsa boleh datang lebih cepat, tidak boleh terlambat');
        $this->assertTrue($tersisa->first()->perlu_diperiksa,
            'baris hasil gabungan masuk hitung fisik berikutnya tanpa perlu ada orang yang mengingatnya');

        // Yang paling mudah hilang tanpa jejak: stock_movements.stock_id memakai
        // cascadeOnDelete, jadi menghapus baris kembar sebelum mutasinya dipindah akan
        // ikut menghapus kartu stoknya.
        $this->assertSame($tua, StockMovement::query()->find($mutasiTua)->stock_id);
        $this->assertSame($tua, StockMovement::query()->find($mutasiMuda)->stock_id,
            'mutasi baris yang dihapus harus pindah induk, bukan ikut terhapus');
        $this->assertSame(2, StockMovement::query()->count());

        // Index-nya dipasang kembali setelah datanya bersih, jadi kembarannya tidak bisa
        // lahir lagi lewat pintu yang sama.
        $this->assertContains(
            ['outlet_id', 'product_id'],
            collect(Schema::getIndexes('stocks'))->where('unique', true)->pluck('columns')->all(),
        );
    }

    /**
     * Migrasi ini penjamin, bukan pemilik: kedua unique index-nya dideklarasikan
     * create_stocks_table. Jalan dua kali harus aman (tidak melempar "duplicate key"),
     * dan `down()` harus bersih — ia sengaja tidak menjatuhkan index milik migrasi lain,
     * karena rollback satu langkah yang menghapusnya justru mengembalikan cacat ini.
     */
    public function test_migrasi_penjamin_aman_diulang_dan_down_berjalan_bersih(): void
    {
        $this->jalankanMigrasiPenjamin();
        $this->jalankanMigrasiPenjamin();

        $migrasi = require database_path('migrations/2026_08_05_090000_pastikan_baris_stok_unik_per_outlet.php');
        $migrasi->down();

        $unik = collect(Schema::getIndexes('stocks'))->where('unique', true)->pluck('columns')->all();

        $this->assertContains(['outlet_id', 'product_id'], $unik);
        $this->assertContains(['outlet_id', 'raw_material_id'], $unik);

        // Tabelnya masih hidup dan masih bisa dipakai sesudah down().
        $produk = $this->buatProduk('Kopi Sachet');
        $this->assertNotNull($this->siapkan(productId: $produk->getKey()));
    }

    /* ── Pembantu ────────────────────────────────────────────────────────── */

    private function siapkan(?string $productId = null, ?string $rawMaterialId = null, ?string $outletId = null): Stock
    {
        return app(SiapkanBarisStokAction::class)->execute(
            $this->tenant->getKey(),
            $outletId ?? $this->outlet->getKey(),
            $productId,
            $rawMaterialId,
        );
    }

    /**
     * Menyisipkan baris pesaing tepat sebelum INSERT milik aksi mendarat, lalu melepas
     * dirinya sendiri supaya panggilan berikutnya berjalan normal.
     *
     * Query builder dipakai (bukan Eloquent) supaya penyisipan ini tidak memicu ulang
     * listener yang sedang berjalan.
     *
     * @return string id baris pemenang
     */
    private function pasangPesaing(?string $productId = null, ?string $rawMaterialId = null, float $saldoAwal = 0): string
    {
        $id = (string) Str::uuid();
        $sudah = false;

        Stock::creating(function () use (&$sudah, $id, $productId, $rawMaterialId, $saldoAwal) {
            if ($sudah) {
                return;
            }

            $sudah = true;

            DB::table('stocks')->insert([
                'id' => $id,
                'tenant_id' => $this->tenant->getKey(),
                'outlet_id' => $this->outlet->getKey(),
                'product_id' => $productId,
                'raw_material_id' => $rawMaterialId,
                'jumlah_saat_ini' => $saldoAwal,
                'stok_minimum' => 0,
                'perlu_diperiksa' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $id;
    }

    private function hitungBaris(?string $productId, ?string $rawMaterialId): int
    {
        return DB::table('stocks')
            ->where('outlet_id', $this->outlet->getKey())
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
            ->when($rawMaterialId !== null, fn ($q) => $q->where('raw_material_id', $rawMaterialId))
            ->count();
    }

    /** @param  array<string, mixed>  $tambahan */
    private function sisipkanBarisMentah(?string $productId, ?string $rawMaterialId, float $saldo, string $dibuat, array $tambahan = []): string
    {
        $id = (string) Str::uuid();

        DB::table('stocks')->insert(array_merge([
            'id' => $id,
            'tenant_id' => $this->tenant->getKey(),
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $productId,
            'raw_material_id' => $rawMaterialId,
            'jumlah_saat_ini' => $saldo,
            'stok_minimum' => 0,
            'perlu_diperiksa' => false,
            'created_at' => $dibuat,
            'updated_at' => $dibuat,
        ], $tambahan));

        return $id;
    }

    private function sisipkanMutasiMentah(string $stockId, float $jumlah): string
    {
        $id = (string) Str::uuid();

        DB::table('stock_movements')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->getKey(),
            'outlet_id' => $this->outlet->getKey(),
            'stock_id' => $stockId,
            'tipe' => StockMovementType::Masuk->value,
            'jumlah' => $jumlah,
            'saldo_sesudah' => $jumlah,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function jalankanMigrasiPenjamin(): void
    {
        $migrasi = require database_path('migrations/2026_08_05_090000_pastikan_baris_stok_unik_per_outlet.php');

        $migrasi->up();
    }

    private function buatProduk(string $nama): Product
    {
        return Product::create([
            'nama_produk' => $nama,
            'harga_default' => 2000,
            'satuan' => Satuan::Pcs,
        ]);
    }

    private function buatBahan(string $nama): RawMaterial
    {
        return RawMaterial::create([
            'nama' => $nama,
            'satuan' => Satuan::Kg,
        ]);
    }

    private function jual(Product $produk, float $qty, float $harga): Transaction
    {
        $transaksi = Transaction::create([
            'outlet_id' => $this->outlet->getKey(),
            'staff_id' => $this->kasir->getKey(),
            'nomor_transaksi' => 'TRX-'.str()->random(6),
            'mode' => TransactionMode::Langsung,
            'subtotal' => $qty * $harga,
            'total' => $qty * $harga,
            'waktu_transaksi' => now(),
        ]);

        TransactionItem::create([
            'transaction_id' => $transaksi->getKey(),
            'product_id' => $produk->getKey(),
            'nama_produk' => $produk->nama_produk,
            'qty' => $qty,
            'harga_satuan' => $harga,
            'subtotal' => $qty * $harga,
        ]);

        app(ApplySaleToStockAction::class)->execute($transaksi->fresh(), $this->kasir->getKey());

        return $transaksi;
    }
}
