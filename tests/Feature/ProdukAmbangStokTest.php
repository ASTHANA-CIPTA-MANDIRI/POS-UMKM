<?php

namespace Tests\Feature;

use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Dasbor\Dasbor;
use App\Livewire\Pages\Owner\Produk\Produk;
use App\Models\Bahan\RawMaterial;
use App\Models\Bahan\RecipeItem;
use App\Models\Produk\Product;
use App\Models\Stok\Stock;
use App\Models\Stok\StockMovement;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Konversi satuan & ambang stok minimum dari formulir produk.
 *
 * Dua hal yang diuji di sini sama-sama tidak terlihat saat salah:
 *
 * 1. isi_per_satuan yang keliru tidak pernah memunculkan galat dan tidak pernah membuat
 *    uang salah. Yang terjadi hanya stok yang meleleh (jual 1 pcs memotong 12 pcs) dan
 *    opname yang menambalnya berulang kali tanpa ada yang tahu sebabnya.
 * 2. Ambang minimum tinggal di tabel stocks, PER OUTLET — bukan di products. Menyimpannya
 *    ke outlet yang salah, atau mencatatnya sebagai mutasi stok, sama-sama mengarang data
 *    yang tidak pernah terjadi.
 */
class ProdukAmbangStokTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Ambang');
        $this->outlet = $this->buatOutlet($this->tenant, 'Outlet Satu');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Ambang',
            'outlet_id' => $this->outlet->getKey(),
        ]);
    }

    /* ── Validasi konversi satuan ────────────────────────────────────────── */

    /**
     * Satuan jual = satuan dasar, tapi isinya diisi 12.
     *
     * Kombinasi yang paling mudah terjadi: orang memilih "pcs" di kedua kolom lalu
     * mengetik isi dus di kolom yang salah. Kalau lolos, tiap penjualan 1 pcs memotong
     * 12 pcs dari kartu stok — tanpa galat, tanpa uang yang salah.
     */
    public function test_isi_per_satuan_12_saat_satuan_sama_dengan_satuan_dasar_ditolak(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('tambah')
            ->set('nama', 'Kerupuk')
            ->set('harga', 2000)
            ->set('satuan', 'pcs')
            ->set('satuanDasar', 'pcs')
            ->set('isiPerSatuan', 12)
            ->call('simpan')
            ->assertHasErrors('isiPerSatuan');

        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    /** Faktor 1 (atau kosong) sah untuk satuan yang sama — itu memang artinya. */
    public function test_isi_per_satuan_1_saat_satuan_sama_dengan_satuan_dasar_diterima(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('tambah')
            ->set('nama', 'Kerupuk')
            ->set('harga', 2000)
            ->set('satuan', 'pcs')
            ->set('satuanDasar', 'pcs')
            ->set('isiPerSatuan', 1)
            ->call('simpan')
            ->assertHasNoErrors();

        $produk = Product::withoutGlobalScopes()->sole();

        $this->assertSame(Satuan::Pcs, $produk->satuan_dasar);
        $this->assertEqualsWithDelta(1.0, $produk->keSatuanDasar(1), 0.001);
    }

    /**
     * Isi terisi tanpa satuan dasar.
     *
     * Angka ini tidak punya satuan tujuan, tapi Product::keSatuanDasar() tetap
     * mengalikan — jadi ia bekerja sepenuhnya dalam kegelapan.
     */
    public function test_isi_per_satuan_terisi_tanpa_satuan_dasar_ditolak(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('tambah')
            ->set('nama', 'Air Mineral')
            ->set('harga', 36000)
            ->set('satuan', 'dus')
            ->set('satuanDasar', '')
            ->set('isiPerSatuan', 12)
            ->call('simpan')
            ->assertHasErrors('isiPerSatuan');

        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    /**
     * Satuan dasar beda tapi isinya dibiarkan kosong.
     *
     * Tanpa faktor, 1 dus dianggap 1 pcs: stok turun 1 padahal 12 keluar dari rak.
     * Arah kesalahannya berlawanan dengan kasus di atas, akibatnya sama — kartu stok
     * berhenti bisa dipercaya.
     */
    public function test_satuan_dasar_beda_tapi_isi_per_satuan_kosong_ditolak(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('tambah')
            ->set('nama', 'Air Mineral')
            ->set('harga', 36000)
            ->set('satuan', 'dus')
            ->set('satuanDasar', 'pcs')
            ->set('isiPerSatuan', null)
            ->call('simpan')
            ->assertHasErrors(['isiPerSatuan' => 'required']);

        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    public function test_konversi_yang_lengkap_tersimpan(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('tambah')
            ->set('nama', 'Air Mineral 600ml')
            ->set('harga', 36000)
            ->set('satuan', 'dus')
            ->set('satuanDasar', 'pcs')
            ->set('isiPerSatuan', 12)
            ->call('simpan')
            ->assertHasNoErrors();

        $produk = Product::withoutGlobalScopes()->sole();

        $this->assertSame(Satuan::Dus, $produk->satuan);
        $this->assertSame(Satuan::Pcs, $produk->satuan_dasar);
        $this->assertEqualsWithDelta(24.0, $produk->keSatuanDasar(2), 0.001);
    }

    /** Faktor 0 dan negatif akan membuat stok tidak pernah berkurang atau justru bertambah. */
    public function test_isi_per_satuan_nol_atau_negatif_ditolak(): void
    {
        foreach ([0, -12] as $nilai) {
            Livewire::actingAs($this->owner)
                ->test(Produk::class)
                ->call('tambah')
                ->set('nama', 'Air Mineral')
                ->set('harga', 36000)
                ->set('satuan', 'dus')
                ->set('satuanDasar', 'pcs')
                ->set('isiPerSatuan', $nilai)
                ->call('simpan')
                ->assertHasErrors('isiPerSatuan');
        }

        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    /** Mengosongkan satuan dasar harus ikut membuang faktornya, bukan menyisakannya. */
    public function test_mengosongkan_satuan_dasar_membuang_isi_per_satuan(): void
    {
        $produk = $this->buatProduk('Air Mineral', [
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 12,
        ]);

        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('ubah', $produk->getKey())
            ->set('satuanDasar', '')
            ->set('isiPerSatuan', null)
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertNull($produk->fresh()->satuan_dasar);
        $this->assertNull($produk->fresh()->isi_per_satuan);
        $this->assertEqualsWithDelta(2.0, $produk->fresh()->keSatuanDasar(2), 0.001);
    }

    /**
     * Menyimpan ulang tanpa menyentuh kolom konversi tidak boleh menghapusnya.
     *
     * Penting justru SEKARANG: kolomnya belum ada di formulir (Blade menyusul), jadi
     * setiap penyimpanan produk lewat layar ini berjalan tanpa kolom itu diisi manusia.
     * Kalau nilainya tidak dimuat ulang di ubah(), owner yang cuma mengoreksi harga akan
     * ikut menghapus konversi satuannya — dan sesudah itu jual 1 dus hanya memotong 1 pcs.
     */
    public function test_menyimpan_ulang_tanpa_menyentuh_konversi_mempertahankan_isi_per_satuan(): void
    {
        $produk = $this->buatProduk('Air Mineral', [
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 12,
        ]);

        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('ubah', $produk->getKey())
            ->set('harga', 38000)
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame(Satuan::Pcs, $produk->fresh()->satuan_dasar);
        $this->assertEqualsWithDelta(24.0, $produk->fresh()->keSatuanDasar(2), 0.001);
    }

    /* ── Ambang minimum per outlet ───────────────────────────────────────── */

    /**
     * Menyetel ambang pada produk yang belum punya baris stocks membuat barisnya dengan
     * jumlah 0 — dan TIDAK membuat satu pun baris stock_movements.
     *
     * Ambang bukan pergerakan barang. Kalau penyetelannya ikut dicatat sebagai mutasi,
     * kartu stok berisi kejadian yang tidak pernah terjadi, dan kartu stok adalah
     * satu-satunya bukti yang tersisa saat ada selisih yang harus dijelaskan.
     */
    public function test_ambang_untuk_produk_tanpa_baris_stocks_membuat_baris_jumlah_nol_tanpa_mutasi(): void
    {
        $produk = $this->buatProduk('Beras 5kg');

        $this->assertSame(0, Stock::withoutGlobalScopes()->count());

        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('ubah', $produk->getKey())
            ->set('stokMinimum', 5)
            ->call('simpan')
            ->assertHasNoErrors();

        $stok = Stock::withoutGlobalScopes()->sole();

        $this->assertSame($this->outlet->getKey(), $stok->outlet_id);
        $this->assertSame($produk->getKey(), $stok->product_id);
        $this->assertEqualsWithDelta(0.0, (float) $stok->jumlah_saat_ini, 0.001,
            'baris baru harus bersaldo 0 — belum ada barang yang dihitung');
        $this->assertEqualsWithDelta(5.0, (float) $stok->stok_minimum, 0.001);
        $this->assertSame($this->tenant->getKey(), $stok->tenant_id);

        $this->assertSame(0, StockMovement::withoutGlobalScopes()->count(),
            'menyetel ambang bukan pergerakan barang; tidak boleh ada mutasi');
    }

    /** Menyimpan produk tanpa mengisi ambang tidak boleh membuat baris stok kosong. */
    public function test_ambang_kosong_tidak_membuat_baris_stok(): void
    {
        $produk = $this->buatProduk('Gula');

        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('ubah', $produk->getKey())
            ->set('harga', 15000)
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame(0, Stock::withoutGlobalScopes()->count());
    }

    /** Mengubah ambang pada baris yang sudah ada tidak boleh menyentuh saldonya. */
    public function test_mengubah_ambang_tidak_mengubah_saldo_dan_tidak_membuat_mutasi(): void
    {
        $produk = $this->buatProduk('Minyak Goreng');

        $stok = $this->konteks()->forTenant($this->tenant->getKey(), fn () => Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => 7,
            'stok_minimum' => 2,
        ]));

        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('ubah', $produk->getKey())
            ->assertSet('stokMinimum', 2.0)
            ->set('stokMinimum', 10)
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(10.0, (float) $stok->fresh()->stok_minimum, 0.001);
        $this->assertEqualsWithDelta(7.0, (float) $stok->fresh()->jumlah_saat_ini, 0.001,
            'saldo tidak boleh ikut berubah');
        $this->assertSame(0, StockMovement::withoutGlobalScopes()->count());
    }

    /**
     * Ambang untuk outlet A tidak boleh menyentuh baris outlet B.
     *
     * Satu produk dijual di dua cabang dengan kecepatan berbeda: gudang pusat wajar
     * berambang 50, kios kecil berambang 5. Ambang yang bocor antar outlet membuat kios
     * memesan barang yang tidak dibutuhkannya.
     */
    public function test_ambang_untuk_outlet_a_tidak_mengubah_baris_outlet_b(): void
    {
        $outletB = $this->buatOutlet($this->tenant, 'Outlet Dua');
        $produk = $this->buatProduk('Air Mineral');

        $stokB = $this->konteks()->forTenant($this->tenant->getKey(), fn () => Stock::create([
            'outlet_id' => $outletB->getKey(),
            'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => 40,
            'stok_minimum' => 3,
        ]));

        // Owner tidak terkunci ke satu outlet, jadi outletnya dipilih di komponen.
        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->set('outletStok', $this->outlet->getKey())
            ->call('ubah', $produk->getKey())
            ->set('stokMinimum', 25)
            ->call('simpan')
            ->assertHasNoErrors();

        $stokA = Stock::withoutGlobalScopes()
            ->where('outlet_id', $this->outlet->getKey())
            ->sole();

        $this->assertEqualsWithDelta(25.0, (float) $stokA->stok_minimum, 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $stokB->fresh()->stok_minimum, 0.001,
            'ambang outlet B tidak boleh tersentuh');
        $this->assertEqualsWithDelta(40.0, (float) $stokB->fresh()->jumlah_saat_ini, 0.001);
    }

    /**
     * Berganti outlet memuat ulang ambangnya.
     *
     * Kalau tidak, angka milik outlet A masih tertinggal di formulir dan ikut tersimpan
     * ke outlet B — ambang yang tidak pernah diketik siapa pun untuk outlet itu.
     */
    public function test_berganti_outlet_memuat_ambang_outlet_itu(): void
    {
        $outletB = $this->buatOutlet($this->tenant, 'Outlet Dua');
        $produk = $this->buatProduk('Air Mineral');

        $this->konteks()->forTenant($this->tenant->getKey(), function () use ($produk, $outletB) {
            Stock::create([
                'outlet_id' => $this->outlet->getKey(),
                'product_id' => $produk->getKey(),
                'jumlah_saat_ini' => 10,
                'stok_minimum' => 4,
            ]);

            Stock::create([
                'outlet_id' => $outletB->getKey(),
                'product_id' => $produk->getKey(),
                'jumlah_saat_ini' => 90,
                'stok_minimum' => 50,
            ]);
        });

        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->set('outletStok', $this->outlet->getKey())
            ->call('ubah', $produk->getKey())
            ->assertSet('stokMinimum', 4.0)
            ->set('outletStok', $outletB->getKey())
            ->assertSet('stokMinimum', 50.0);
    }

    /**
     * Outlet milik tenant lain ditolak.
     *
     * outlet_id datang dari klien, dan muatan Livewire bisa disusun sendiri. Tanpa
     * pemeriksaan ini, baris stocks kita akan menunjuk outlet orang lain — data bocor
     * ke arah yang paling sulit ditemukan, yaitu ke dalam tabel kita sendiri.
     */
    public function test_outlet_tenant_lain_ditolak(): void
    {
        $tenantLain = $this->buatTenant('Warung Sebelah');
        $outletLain = $this->buatOutlet($tenantLain, 'Outlet Sebelah');
        $produk = $this->buatProduk('Air Mineral');

        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->set('outletStok', $outletLain->getKey())
            ->call('ubah', $produk->getKey())
            ->set('stokMinimum', 9)
            ->call('simpan')
            ->assertHasErrors('outletStok');

        $this->assertSame(0, Stock::withoutGlobalScopes()->count());
    }

    /* ── Saringan "menipis" ──────────────────────────────────────────────── */

    /**
     * Saringan daftar produk memakai aturan yang SAMA dengan kartu dasbor.
     *
     * Kalau berbeda, owner melihat "3 barang menipis" di dasbor lalu menemukan lima baris
     * saat saringannya dibuka — dan sesudah itu ia berhenti mempercayai keduanya. Syarat
     * `stok_minimum > 0` adalah bagian yang paling mudah tertinggal saat aturannya
     * disalin: tanpa itu semua barang bersaldo nol ikut terhitung menipis (0 <= 0).
     */
    public function test_saringan_menipis_sama_dengan_aturan_dasbor(): void
    {
        $menipis = $this->buatProdukBerstok('Beras', jumlah: 2, minimum: 5);
        $tepatAmbang = $this->buatProdukBerstok('Gula', jumlah: 5, minimum: 5);
        $aman = $this->buatProdukBerstok('Kopi', jumlah: 50, minimum: 5);
        // Ambang 0 = tidak dipantau. Saldo nol pun tidak boleh terhitung menipis,
        // kalau tidak angkanya berisi seluruh katalog yang belum pernah diopname.
        $tanpaAmbang = $this->buatProdukBerstok('Teh', jumlah: 0, minimum: 0);

        $daftar = Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->set('stok', 'menipis');

        $daftar->assertSee('Beras')
            ->assertSee('Gula')
            ->assertDontSee('Kopi')
            ->assertDontSee('Teh');

        $this->assertSame(2, $this->jumlahMenipisDiDaftar());

        // Angka yang sama harus keluar dari kartu dasbor.
        Livewire::actingAs($this->owner)
            ->test(Dasbor::class)
            ->assertViewHas('stokMenipis', 2);

        // Baris yang benar, bukan cuma jumlah yang benar.
        $this->assertEqualsWithDelta(2.0, (float) $menipis->stocks()->sole()->jumlah_saat_ini, 0.001);
        $this->assertTrue($tepatAmbang->stocks()->sole()->isLow());
        $this->assertFalse($aman->stocks()->sole()->isLow());
        $this->assertFalse($tanpaAmbang->stocks()->sole()->isLow());
    }

    /**
     * Produk resep dan produk tanpa lacak_stok tidak boleh muncul di saringan menipis.
     *
     * Menu berbasis resep mengurangi BAHAN BAKUNYA saat terjual, jadi baris stok produk
     * jadinya tidak pernah bergerak — ia akan tampak "menipis" selamanya. Produk tanpa
     * lacak_stok memang tidak dicatat jumlahnya. Keduanya mengisi daftar belanja dengan
     * barang yang tidak perlu dibeli, dan daftar belanja yang berisi sampah tidak dipakai
     * siapa pun.
     */
    public function test_produk_resep_dan_lacak_stok_mati_tidak_muncul_di_saringan_menipis(): void
    {
        $nyata = $this->buatProdukBerstok('Beras', jumlah: 1, minimum: 5);

        $tanpaLacak = $this->buatProdukBerstok('Es Teh Manis', jumlah: 0, minimum: 5, atribut: [
            'lacak_stok' => false,
        ]);

        $resep = $this->buatProdukBerstok('Nasi Goreng', jumlah: 0, minimum: 5);

        $this->konteks()->forTenant($this->tenant->getKey(), function () use ($resep) {
            $beras = RawMaterial::create(['nama' => 'Beras Curah', 'satuan' => Satuan::Kg]);

            RecipeItem::create([
                'product_id' => $resep->getKey(),
                'raw_material_id' => $beras->getKey(),
                'jumlah_terpakai' => 0.2,
            ]);
        });

        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->set('stok', 'menipis')
            ->assertSee('Beras')
            ->assertDontSee('Es Teh Manis')
            ->assertDontSee('Nasi Goreng');

        $this->assertSame(1, $this->jumlahMenipisDiDaftar());
        $this->assertSame($nyata->getKey(), $this->produkDiSaringanMenipis()->sole()->getKey());

        // Barisnya tetap ada dan tetap terhitung di dasbor — yang dikecualikan hanya
        // tampilannya di daftar produk, bukan datanya.
        $this->assertSame(3, Stock::withoutGlobalScopes()->count());
        $this->assertTrue($tanpaLacak->stocks()->sole()->isLow());
    }

    /** Saringan tidak boleh membawa produk tenant lain. */
    public function test_saringan_menipis_tidak_membawa_produk_tenant_lain(): void
    {
        $this->buatProdukBerstok('Beras Kita', jumlah: 1, minimum: 5);

        $tenantLain = $this->buatTenant('Warung Sebelah');
        $outletLain = $this->buatOutlet($tenantLain, 'Outlet Sebelah');

        $this->konteks()->forTenant($tenantLain->getKey(), function () use ($outletLain) {
            $produk = Product::create([
                'nama_produk' => 'Beras Sebelah',
                'harga_default' => 12000,
                'satuan' => Satuan::Kg,
            ]);

            Stock::create([
                'outlet_id' => $outletLain->getKey(),
                'product_id' => $produk->getKey(),
                'jumlah_saat_ini' => 0,
                'stok_minimum' => 9,
            ]);
        });

        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->set('stok', 'menipis')
            ->assertSee('Beras Kita')
            ->assertDontSee('Beras Sebelah');

        $this->assertSame(1, $this->jumlahMenipisDiDaftar());
    }

    /** Kolom stok diambil dari outlet yang di-resolve, bukan dijumlahkan antar outlet. */
    public function test_kolom_stok_menampilkan_saldo_outlet_terpilih(): void
    {
        $outletB = $this->buatOutlet($this->tenant, 'Outlet Dua');
        $produk = $this->buatProduk('Air Mineral');

        $this->konteks()->forTenant($this->tenant->getKey(), function () use ($produk, $outletB) {
            Stock::create([
                'outlet_id' => $this->outlet->getKey(),
                'product_id' => $produk->getKey(),
                'jumlah_saat_ini' => 7,
                'stok_minimum' => 3,
            ]);

            Stock::create([
                'outlet_id' => $outletB->getKey(),
                'product_id' => $produk->getKey(),
                'jumlah_saat_ini' => 90,
                'stok_minimum' => 50,
            ]);
        });

        $komponen = Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->set('outletStok', $this->outlet->getKey());

        $baris = $komponen->viewData('daftar')->sole();

        $this->assertEqualsWithDelta(7.0, (float) $baris->jumlah_saat_ini, 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $baris->stok_minimum, 0.001);

        $komponen->set('outletStok', $outletB->getKey());
        $barisB = $komponen->viewData('daftar')->sole();

        $this->assertEqualsWithDelta(90.0, (float) $barisB->jumlah_saat_ini, 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $barisB->stok_minimum, 0.001);
    }

    /**
     * Produk yang belum pernah punya baris stok tetap harus muncul di daftar.
     *
     * LEFT join, bukan join biasa: barang yang hilang dari layar kelola produk adalah
     * barang yang datanya tidak akan pernah diperbaiki.
     */
    public function test_produk_tanpa_baris_stok_tetap_muncul_di_daftar(): void
    {
        $this->buatProduk('Barang Baru');

        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->assertSee('Barang Baru');
    }

    /** Peran yang terkunci ke satu outlet tidak bisa menyetel ambang outlet lain. */
    public function test_peran_terkunci_mengabaikan_pilihan_outlet_dari_klien(): void
    {
        $outletB = $this->buatOutlet($this->tenant, 'Outlet Dua');
        $produk = $this->buatProduk('Air Mineral');

        $manager = $this->buatUser($this->tenant, UserRole::ManagerOutlet, [
            'name' => 'Manager Satu',
            'outlet_id' => $this->outlet->getKey(),
        ]);

        Livewire::actingAs($manager)
            ->test(Produk::class)
            ->set('outletStok', $outletB->getKey())
            ->call('ubah', $produk->getKey())
            ->set('stokMinimum', 6)
            ->call('simpan')
            ->assertHasNoErrors();

        $stok = Stock::withoutGlobalScopes()->sole();

        $this->assertSame($this->outlet->getKey(), $stok->outlet_id,
            'outletnya diambil dari sesi login, bukan dari muatan klien');
    }

    /* ── Pembantu ────────────────────────────────────────────────────────── */

    private function buatProduk(string $nama, array $atribut = []): Product
    {
        return $this->konteks()->forTenant($this->tenant->getKey(), fn () => Product::create(array_merge([
            'nama_produk' => $nama,
            'harga_default' => 10000,
            'satuan' => Satuan::Pcs,
        ], $atribut)));
    }

    private function buatProdukBerstok(string $nama, float $jumlah, float $minimum, array $atribut = []): Product
    {
        $produk = $this->buatProduk($nama, $atribut);

        $this->konteks()->forTenant($this->tenant->getKey(), fn () => Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => $jumlah,
            'stok_minimum' => $minimum,
        ]));

        return $produk;
    }

    /** Jumlah baris yang benar-benar keluar dari saringan komponen. */
    private function jumlahMenipisDiDaftar(): int
    {
        return $this->produkDiSaringanMenipis()->count();
    }

    private function produkDiSaringanMenipis()
    {
        return Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->set('stok', 'menipis')
            ->viewData('daftar')
            ->getCollection();
    }
}
