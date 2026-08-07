<?php

namespace Tests\Feature;

use App\Enums\Satuan;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Pembelian\PembelianBaru;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\RecipeItem;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataPembelian;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Nota pembelian menggerakkan stok — lewat jalur yang sama dengan semua perubahan stok
 * lain (AdjustStockAction), bukan dengan menulis saldo langsung.
 *
 * Kenapa jumlah BARIS stock_movements yang diperiksa, bukan hanya saldonya: saldo yang
 * benar bisa dihasilkan oleh `$stok->jumlah_saat_ini += $qty; $stok->save()`, dan cara itu
 * tidak meninggalkan satu baris pun di kartu stok. Angkanya cocok hari ini; enam bulan
 * kemudian pemilik membuka riwayat barang untuk mencari kapan stoknya mulai salah dan
 * menemukan lompatan tanpa penjelasan. Kartu stok yang bolong adalah cacat yang tidak
 * pernah terlihat pada hari ia dibuat.
 */
class PembelianStokTest extends TestCase
{
    use MembuatDataPembelian, MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Belanja');
        $this->outlet = $this->buatOutlet($this->tenant, 'Cabang Belanja');
        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Belanja',
            'email' => 'owner@belanja.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    public function test_menyimpan_nota_menambah_stok_lewat_adjust_stock_action(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $gula = $this->buatProduk('Gula Pasir', ['satuan' => Satuan::Kg]);

        $this->buatStok($this->outlet, $kopi, 10);
        $this->buatStok($this->outlet, $gula, 4);

        $nota = $this->catatNota($this->outlet, $this->owner, [
            'beli_dari' => 'Grosir Amanah',
            'baris' => [
                $this->baris($kopi, 20, 1500),
                $this->baris($gula, 6, 14000),
            ],
        ]);

        $this->assertEqualsWithDelta(30.0, $this->saldo($this->outlet, $kopi), 0.001);
        $this->assertEqualsWithDelta(10.0, $this->saldo($this->outlet, $gula), 0.001);

        // SATU baris kartu stok per baris nota — bukan nol, dan bukan tiga.
        $this->assertSame(2, StockMovement::query()->count(),
            'tiap baris nota harus meninggalkan tepat satu baris kartu stok');

        $mutasi = StockMovement::query()->get();

        foreach ($mutasi as $satu) {
            $this->assertSame(StockMovementType::Masuk, $satu->tipe);
            $this->assertSame($nota->getKey(), $satu->referensi_id,
                'mutasinya harus menunjuk nota sumbernya, supaya riwayat barang bisa dibuka sampai ke notanya');
            $this->assertSame('purchase_order', $satu->referensi_type);
            $this->assertSame($this->owner->getKey(), $satu->oleh_user_id);
            $this->assertNull($satu->alasan,
                'kolom alasan hanya untuk selisih hitung stok; mengisinya di sini membuat laporan selisih penuh baris yang bukan selisih');
        }

        $mutasiKopi = $mutasi->first(fn (StockMovement $satu) => (float) $satu->jumlah === 20.0);
        $this->assertNotNull($mutasiKopi);
        $this->assertEqualsWithDelta(30.0, (float) $mutasiKopi->saldo_sesudah, 0.001);
    }

    /**
     * KONVERSI SATUAN — risiko diam-diam nomor satu di layar ini.
     *
     * Pemilik mengetik "2" karena ia membeli 2 dus. Yang masuk rak adalah 24 pcs, dan itu
     * yang dihitung saat opname. Kalau yang tersimpan 2, seluruh angka stoknya salah 12
     * kali lipat tanpa satu pun galat — dan opname berikutnya akan "memperbaikinya"
     * menjadi selisih +22 yang tidak ada penjelasannya.
     *
     * Faktornya dibaca dari master (`isi_per_satuan`), TIDAK dari medan di formulir:
     * dua tempat yang mengisi faktor konversi berarti dua kebenaran, dan blok "Harus
     * belanja" di layar stok hanya membaca yang di master.
     */
    public function test_dua_dus_isi_dua_belas_menambah_dua_puluh_empat_satuan_dasar(): void
    {
        $susu = $this->buatProduk('Susu Kotak', [
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 12,
        ]);

        $this->buatStok($this->outlet, $susu, 0);

        $nota = $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($susu, 2, 58000)],
        ]);

        $this->assertEqualsWithDelta(24.0, $this->saldo($this->outlet, $susu), 0.001);

        $item = PurchaseOrderItem::query()->where('purchase_order_id', $nota->getKey())->sole();

        $this->assertEqualsWithDelta(24.0, (float) $item->qty, 0.001, 'qty = satuan dasar');
        $this->assertEqualsWithDelta(24.0, (float) $item->qty_diterima, 0.001, 'nota disimpan berarti barangnya sudah datang');
        $this->assertEqualsWithDelta(2.0, (float) $item->qty_beli, 0.001, 'qty_beli = angka yang diketik pemilik');
        $this->assertSame('dus', $item->satuan_beli);

        // POTRET faktornya ikut tersimpan: master boleh berubah kemasan (isi 12 jadi isi
        // 10), dan nota lama harus tetap menceritakan pembelian yang benar-benar terjadi.
        $this->assertEqualsWithDelta(12.0, (float) $item->isi_per_satuan_beli, 0.001);

        $susu->update(['isi_per_satuan' => 10]);

        $this->assertEqualsWithDelta(12.0, (float) $item->fresh()->isi_per_satuan_beli, 0.001,
            'nota lama tidak boleh ikut berubah saat kemasan di master diganti');
    }

    public function test_produk_tanpa_konversi_diketik_dalam_satuan_dasar_apa_adanya(): void
    {
        $kerupuk = $this->buatProduk('Kerupuk Bungkus');
        $this->buatStok($this->outlet, $kerupuk, 5);

        $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($kerupuk, 7, 4500)],
        ]);

        $this->assertEqualsWithDelta(12.0, $this->saldo($this->outlet, $kerupuk), 0.001,
            'tanpa isi_per_satuan, angka yang diketik memang sudah dalam satuan pencatatan');
    }

    /**
     * Barang yang belum pernah dicatat di outlet ini justru yang paling sering dibeli
     * pertama kali. Kalau barisnya tidak dibuat, notanya "tersimpan" tapi stoknya tetap
     * kosong — dan tidak ada satu pun galat yang memberi tahu.
     */
    public function test_membeli_barang_yang_belum_punya_baris_stok_membuat_barisnya(): void
    {
        $teh = $this->buatProduk('Teh Celup');

        $this->assertNull($this->saldo($this->outlet, $teh), 'prasyarat: belum ada baris stoknya');

        $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($teh, 15, 5000)],
        ]);

        $this->assertEqualsWithDelta(15.0, $this->saldo($this->outlet, $teh), 0.001);
        $this->assertSame(1, Stock::query()->where('product_id', $teh->getKey())->count(),
            'satu barang di satu outlet hanya boleh punya satu baris stok');
    }

    /**
     * Aturan 5 CLAUDE.md: stok boleh minus. Saldo minus lahir dari penjualan offline yang
     * masuk belakangan, dan pembelian TIDAK boleh menolak atau "membersihkan"-nya jadi nol
     * — nol akan menghapus bukti bahwa pencatatannya pernah bermasalah.
     */
    public function test_stok_minus_tiga_ditambah_sepuluh_menjadi_tujuh(): void
    {
        $mie = $this->buatProduk('Mie Instan');
        $this->buatStok($this->outlet, $mie, -3);

        $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($mie, 10, 2900)],
        ]);

        $this->assertEqualsWithDelta(7.0, $this->saldo($this->outlet, $mie), 0.001);
    }

    /**
     * Barang MASUK bukan barang yang DIHITUNG.
     *
     * Mematikan `perlu_diperiksa` karena ada barang datang akan menghapus pengingat
     * memeriksa selisih yang belum pernah diperiksa — dan "terakhir dihitung" yang bergeser
     * karena pembelian membuat pemilik yakin barangnya baru saja dihitung padahal tidak ada
     * yang menghitungnya.
     */
    public function test_pembelian_tidak_mengubah_opname_terakhir_dan_tidak_mematikan_perlu_diperiksa(): void
    {
        $beras = $this->buatProduk('Beras 5kg');
        $stok = $this->buatStok($this->outlet, $beras, 12, [
            'opname_terakhir_pada' => now()->subDays(9),
            'perlu_diperiksa' => true,
        ]);

        $sebelum = $stok->fresh()->opname_terakhir_pada;

        $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($beras, 5, 61000)],
        ]);

        $sesudah = $stok->fresh();

        $this->assertSame(
            $sebelum->toDateTimeString(),
            $sesudah->opname_terakhir_pada->toDateTimeString(),
            'pembelian tidak boleh menggeser tanggal "terakhir dihitung"',
        );
        $this->assertTrue($sesudah->perlu_diperiksa,
            'bendera "perlu diperiksa" hanya boleh dimatikan oleh penghitungan fisik');
        $this->assertEqualsWithDelta(17.0, (float) $sesudah->jumlah_saat_ini, 0.001);
    }

    public function test_bahan_baku_bisa_dibeli(): void
    {
        $ayam = $this->buatBahan('Ayam Potong');
        $this->buatStok($this->outlet, $ayam, 2);

        $nota = $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($ayam, 10, 38000)],
        ]);

        $this->assertEqualsWithDelta(12.0, $this->saldo($this->outlet, $ayam), 0.001);

        $item = PurchaseOrderItem::query()->where('purchase_order_id', $nota->getKey())->sole();

        $this->assertSame($ayam->getKey(), $item->raw_material_id);
        $this->assertNull($item->product_id);
        $this->assertSame('kg', $item->satuan_beli);
        $this->assertNull($item->isi_per_satuan_beli,
            'bahan baku belum punya konversi satuan beli — angkanya memang sudah dalam satuannya sendiri');

        // Bahan baku menyimpan harga terakhirnya di kolomnya sendiri.
        $this->assertEqualsWithDelta(38000.0, (float) $ayam->fresh()->harga_beli_terakhir, 0.01);
    }

    /**
     * Menu berbasis resep TIDAK boleh bisa dibeli.
     *
     * Yang dibeli untuk sepiring ayam goreng adalah ayamnya, bukan "ayam gorengnya". Stok
     * menu jadi tidak pernah berkurang saat menunya terjual (yang berkurang bahan bakunya),
     * jadi menaikkannya lewat pembelian menghasilkan angka yang naik terus dan tidak pernah
     * turun — lalu ikut terhitung di nilai persediaan sebagai uang yang tidak ada.
     */
    public function test_produk_berbasis_resep_tidak_muncul_sebagai_pilihan_baris(): void
    {
        $ayamGoreng = $this->buatProduk('Ayam Goreng');
        $bahan = $this->buatBahan('Ayam Mentah');

        RecipeItem::create([
            'product_id' => $ayamGoreng->getKey(),
            'raw_material_id' => $bahan->getKey(),
            'jumlah_terpakai' => 0.25,
        ]);

        $kopi = $this->buatProduk('Kopi Sachet');

        $kunci = collect(
            Livewire::actingAs($this->owner)->test(PembelianBaru::class)->viewData('daftar')->items()
        )->pluck('kunci');

        $this->assertTrue($kunci->contains($kopi->getKey()), 'barang biasa harus bisa dibeli');
        $this->assertTrue($kunci->contains($bahan->getKey()), 'bahan bakunya justru yang dibeli');
        $this->assertFalse($kunci->contains($ayamGoreng->getKey()),
            'menu berbasis resep tidak boleh muncul sebagai baris yang bisa dibeli');
    }

    /** Produk yang stoknya tidak dilacak (jasa) juga tidak punya arti di nota belanja. */
    public function test_produk_tanpa_lacak_stok_tidak_muncul_sebagai_pilihan_baris(): void
    {
        $jasa = $this->buatProduk('Jasa Antar', ['lacak_stok' => false]);

        $kunci = collect(
            Livewire::actingAs($this->owner)->test(PembelianBaru::class)->viewData('daftar')->items()
        )->pluck('kunci');

        $this->assertFalse($kunci->contains($jasa->getKey()));
    }

    /** Nomor nota berurut per tenant per tanggal, dan tidak memakai awalan data lama. */
    public function test_nomor_nota_berurut_per_tanggal(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $satu = $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 1, 1500)]]);
        $dua = $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 1, 1500)]]);

        $this->assertSame('PB-'.now()->format('Ymd').'-001', $satu->nomor_po);
        $this->assertSame('PB-'.now()->format('Ymd').'-002', $dua->nomor_po);
    }

    /** Produk milik tenant lain tidak bisa diselipkan ke nota lewat id di muatan. */
    public function test_produk_tenant_lain_tidak_bisa_masuk_ke_nota(): void
    {
        $tetangga = $this->buatTenant('Warung Tetangga');
        $produkTetangga = $this->konteks()->forTenant(
            $tetangga->getKey(),
            fn () => Product::create(['nama_produk' => 'Kopi Tetangga', 'harga_default' => 2000, 'satuan' => Satuan::Pcs]),
        );

        $this->expectException(ModelNotFoundException::class);

        $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($produkTetangga, 5, 1500)],
        ]);
    }
}
