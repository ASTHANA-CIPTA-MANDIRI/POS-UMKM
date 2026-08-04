<?php

namespace Tests\Feature;

use App\Enums\AlasanOpname;
use App\Enums\Satuan;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Opname;
use App\Livewire\Pages\Owner\Stok as LayarStok;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Layar stok: daftar gabungan produk + bahan baku, blok "harus belanja", ambang minimum
 * inline, kartu stok, dan gerbang aksesnya.
 *
 * Blok harus-belanja adalah satu-satunya bagian layar ini yang benar-benar dipakai sambil
 * berdiri di depan grosir. Kalau urutannya salah atau jumlah belinya dibulatkan ke bawah,
 * pemiliknya pulang tetap kurang barang — dan sesudah dua kali begitu ia berhenti memakai
 * daftarnya.
 */
class StokLayarTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Stok');
        $this->outlet = $this->buatOutlet($this->tenant, 'Outlet Stok');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Stok',
            'email' => 'owner@stok.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    /* ── Harus belanja ───────────────────────────────────────────────────── */

    /**
     * Empat barang di bawah ambang, satu barang cukup.
     *
     * Urutannya `jumlah − ambang` menaik: yang paling parah lebih dulu. Mengurutkannya
     * berdasarkan nama (atau tidak mengurutkannya sama sekali) membuat daftar 40 baris
     * tidak bisa dipakai — yang paling gawat bisa berada di baris ke-31, dan yang dibaca
     * orang hanya baris teratas.
     */
    public function test_empat_item_di_bawah_ambang_muncul_di_blok_harus_belanja_paling_parah_dulu(): void
    {
        // −29 (paling parah)
        $air = $this->buatProduk('Air Mineral', [
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 12,
        ]);
        $this->buatStok($air, jumlah: 1, minimum: 30);

        // −8
        $gula = $this->buatProduk('Gula Pasir');
        $this->buatStok($gula, jumlah: 2, minimum: 10);

        // −5, dan sengaja BAHAN BAKU: daftarnya gabungan, bukan produk saja.
        $beras = RawMaterial::create(['nama' => 'Beras', 'satuan' => Satuan::Kg]);
        $this->buatStok($beras, jumlah: 0, minimum: 5);

        // −2
        $teh = $this->buatProduk('Teh Celup');
        $this->buatStok($teh, jumlah: 4, minimum: 6);

        // Cukup stok → tidak boleh muncul.
        $kopi = $this->buatProduk('Kopi Bubuk');
        $this->buatStok($kopi, jumlah: 20, minimum: 5);

        $harusBelanja = Livewire::actingAs($this->owner)
            ->test(LayarStok::class)
            ->viewData('harusBelanja');

        $this->assertSame(
            ['Air Mineral', 'Gula Pasir', 'Beras', 'Teh Celup'],
            $harusBelanja->pluck('nama')->all(),
        );

        $this->assertEqualsWithDelta(29.0, $harusBelanja->firstWhere('nama', 'Air Mineral')['kekurangan'], 0.001);
        $this->assertEqualsWithDelta(5.0, $harusBelanja->firstWhere('nama', 'Beras')['kekurangan'], 0.001);
    }

    /**
     * Kekurangan 29 pcs dengan 1 dus = 12 pcs → daftar menyebut 3 dus.
     *
     * 29 / 12 = 2,4166… Membulatkan ke bawah (2 dus = 24 pcs) berarti pulang dari grosir
     * masih kurang 5 pcs untuk barang yang sudah diketahui kurang sejak awal. Dan warung
     * tidak bisa membeli 2,4 dus.
     */
    public function test_kekurangan_29_pcs_dengan_satu_dus_12_disebut_3_dus_di_daftar_belanja(): void
    {
        $air = $this->buatProduk('Air Mineral 600ml', [
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 12,
        ]);
        $this->buatStok($air, jumlah: 1, minimum: 30);

        $baris = Livewire::actingAs($this->owner)
            ->test(LayarStok::class)
            ->viewData('harusBelanja')
            ->sole();

        $this->assertEqualsWithDelta(29.0, $baris['kekurangan'], 0.001, 'kekurangan dalam satuan dasar');
        $this->assertSame(3.0, $baris['beli']['jumlah'], 'dibulatkan ke ATAS: 2,4 dus tidak bisa dibeli');
        $this->assertSame(Satuan::Dus, $baris['beli']['satuan']);
        $this->assertSame(Satuan::Pcs, $baris['beli']['satuan_dasar']);
    }

    /* ── Ambang minimum inline ───────────────────────────────────────────── */

    /**
     * Layar ini adalah SATU-SATUNYA tempat ambang bahan baku bisa disetel — formulir
     * produk hanya mengurus produk. Tanpa jalur ini, bahan baku tidak akan pernah bisa
     * masuk daftar belanja, karena tanpa ambang tidak ada yang bisa disebut "kurang".
     *
     * Dan menyetel ambang TIDAK boleh membuat mutasi: jumlahnya tidak berubah satu pun,
     * jadi baris di kartu stok berarti mengarang pergerakan barang yang tidak terjadi.
     */
    public function test_ambang_bahan_baku_bisa_disetel_inline_tanpa_membuat_mutasi(): void
    {
        $beras = RawMaterial::create(['nama' => 'Beras', 'satuan' => Satuan::Kg]);

        Livewire::actingAs($this->owner)
            ->test(LayarStok::class)
            ->call('ubahAmbang', $beras->getKey())
            ->set('ambangNilai', '5')
            ->call('simpanAmbang')
            ->assertHasNoErrors();

        $stok = Stock::query()->where('raw_material_id', $beras->getKey())->sole();

        $this->assertEqualsWithDelta(5.0, (float) $stok->stok_minimum, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $stok->jumlah_saat_ini, 0.001);
        $this->assertSame(0, StockMovement::count(), 'menyetel ambang bukan pergerakan barang');

        // Sesudah ambangnya ada, barangnya masuk daftar belanja (saldo 0 dari 5).
        $harusBelanja = Livewire::actingAs($this->owner)
            ->test(LayarStok::class)
            ->viewData('harusBelanja');

        $this->assertSame(['Beras'], $harusBelanja->pluck('nama')->all());
        $this->assertEqualsWithDelta(5.0, $harusBelanja->sole()['kekurangan'], 0.001);
    }

    public function test_ambang_negatif_ditolak(): void
    {
        $produk = $this->buatProduk('Kerupuk');
        $this->buatStok($produk, jumlah: 10);

        Livewire::actingAs($this->owner)
            ->test(LayarStok::class)
            ->call('ubahAmbang', $produk->getKey())
            ->set('ambangNilai', '-2')
            ->call('simpanAmbang')
            ->assertHasErrors('ambangNilai');
    }

    /* ── Kartu stok ──────────────────────────────────────────────────────── */

    /**
     * Tanpa kartu stok, opname jadi fitur tulis-saja: angkanya berubah dan tidak ada
     * seorang pun yang bisa menjawab kenapa. Yang harus terbaca di satu baris: waktu,
     * tipe, alasan, jumlah, saldo sesudah, oleh siapa, dan catatannya.
     */
    public function test_kartu_stok_menampilkan_mutasi_opname_lengkap_dengan_alasan_dan_pelakunya(): void
    {
        $produk = $this->buatProduk('Sabun');
        $stok = $this->buatStok($produk, jumlah: 10);

        Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$produk->getKey(), 7)
            ->set('alasan.'.$produk->getKey(), AlasanOpname::Rusak->value)
            ->call('simpan')
            ->assertHasNoErrors();

        $kartu = Livewire::actingAs($this->owner)
            ->test(LayarStok::class)
            ->call('bukaKartu', $produk->getKey())
            ->viewData('kartu');

        $this->assertCount(1, $kartu);

        $mutasi = $kartu->first();

        $this->assertSame($stok->getKey(), $mutasi->stock_id);
        $this->assertSame(StockMovementType::Opname, $mutasi->tipe);
        $this->assertSame(AlasanOpname::Rusak, $mutasi->alasan);
        $this->assertEqualsWithDelta(-3.0, (float) $mutasi->jumlah, 0.001);
        $this->assertEqualsWithDelta(7.0, (float) $mutasi->saldo_sesudah, 0.001);
        $this->assertSame('Pemilik Stok', $mutasi->olehUser->name);
        $this->assertNotNull($mutasi->created_at);
        $this->assertStringContainsString('fisik 7', (string) $mutasi->catatan);
    }

    /* ── Saringan ────────────────────────────────────────────────────────── */

    public function test_saringan_jenis_status_dan_cari_menyaring_daftar(): void
    {
        $kerupuk = $this->buatProduk('Kerupuk Udang');
        $this->buatStok($kerupuk, jumlah: 2, minimum: 10);

        $kopi = $this->buatProduk('Kopi Bubuk');
        $this->buatStok($kopi, jumlah: 20, minimum: 5);

        $beras = RawMaterial::create(['nama' => 'Beras', 'satuan' => Satuan::Kg]);
        $this->buatStok($beras, jumlah: -1, minimum: 5);

        $komponen = Livewire::actingAs($this->owner)->test(LayarStok::class);

        $this->assertCount(3, $komponen->viewData('daftar')->items());

        $komponen->set('jenis', 'bahan');
        $this->assertSame(['Beras'], collect($komponen->viewData('daftar')->items())->pluck('nama')->all());

        $komponen->set('jenis', 'produk')->set('status', 'menipis');
        $this->assertSame(['Kerupuk Udang'], collect($komponen->viewData('daftar')->items())->pluck('nama')->all());

        $komponen->set('status', 'semua')->set('cari', 'Kopi');
        $this->assertSame(['Kopi Bubuk'], collect($komponen->viewData('daftar')->items())->pluck('nama')->all());

        // "minus" bukan bagian dari "menipis" — urutan status di Stock::statusStok()
        // memang membedakannya, dan saringannya harus mengikuti definisi itu.
        $komponen->set('cari', '')->set('jenis', 'semua')->set('status', 'minus');
        $this->assertSame(['Beras'], collect($komponen->viewData('daftar')->items())->pluck('nama')->all());
    }

    /**
     * Menu berbasis resep tidak boleh masuk daftar stok maupun lembar opname.
     *
     * Yang berkurang saat menu terjual adalah BAHAN BAKUNYA; baris stok menu jadinya
     * tidak pernah bergerak, jadi ia akan tampak "habis" selamanya dan membanjiri daftar
     * belanja dengan barang yang tidak pernah perlu dibeli.
     */
    public function test_produk_berbasis_resep_dan_produk_tanpa_lacak_stok_tidak_masuk_daftar(): void
    {
        $bahan = RawMaterial::create(['nama' => 'Beras', 'satuan' => Satuan::Kg]);

        $menu = $this->buatProduk('Nasi Goreng');
        $menu->recipeItems()->create(['raw_material_id' => $bahan->getKey(), 'jumlah_terpakai' => 0.2]);

        $jasa = $this->buatProduk('Cuci Kering', ['lacak_stok' => false]);

        $nama = collect(
            Livewire::actingAs($this->owner)->test(LayarStok::class)->viewData('daftar')->items()
        )->pluck('nama')->all();

        $this->assertNotContains('Nasi Goreng', $nama);
        $this->assertNotContains('Cuci Kering', $nama);
        $this->assertContains('Beras', $nama, 'bahan bakunya sendiri tetap harus bisa dihitung');
        $this->assertSame(['Cuci Kering', 'Nasi Goreng'], [$jasa->nama_produk, $menu->nama_produk]);
    }

    /* ── Isolasi & gerbang peran ─────────────────────────────────────────── */

    /**
     * Outlet milik tenant lain di URL ditolak keras, bukan dialihkan ke outlet sendiri.
     *
     * Pengalihan diam-diam menyembunyikan percobaannya dan membuat pemiliknya percaya ia
     * sedang melihat outlet yang disebut di URL — lalu ia menghitung fisik satu cabang ke
     * baris stok cabang lain.
     */
    public function test_outlet_tenant_lain_di_url_ditolak_403(): void
    {
        $lain = $this->buatTenant('Warung Sebelah');
        $outletLain = $this->buatOutlet($lain, 'Outlet Sebelah');

        $this->actingAs($this->owner)
            ->get(route('owner.stok', ['outlet' => $outletLain->getKey()]))
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->get(route('owner.stok.opname', ['outlet' => $outletLain->getKey()]))
            ->assertForbidden();
    }

    /** Outlet sendiri tetap bisa dibuka lewat URL — pembandingnya, supaya 403 di atas bukan karena rutenya rusak. */
    public function test_outlet_sendiri_di_url_bisa_dibuka(): void
    {
        $this->actingAs($this->owner)
            ->get(route('owner.stok', ['outlet' => $this->outlet->getKey()]))
            ->assertOk();

        $this->actingAs($this->owner)
            ->get(route('owner.stok.opname', ['outlet' => $this->outlet->getKey()]))
            ->assertOk();
    }

    /**
     * Kasir tidak boleh membuka layar stok maupun lembar opname.
     *
     * Menghitung fisik adalah wewenang pemilik. Membukanya untuk kasir berarti selisih
     * bisa ditutup sendiri oleh orang yang memegang uangnya: laci kurang seratus ribu
     * tinggal dicatat sebagai "rusak", dan tidak ada yang bisa membedakannya dari barang
     * yang benar-benar rusak.
     */
    public function test_kasir_tidak_bisa_membuka_layar_stok_maupun_opname(): void
    {
        $kasir = $this->buatUser($this->tenant, UserRole::Kasir, [
            'username' => 'kasirstok',
            'pin_hash' => '123456',
            'outlet_id' => $this->outlet->getKey(),
        ]);

        $this->actingAs($kasir)
            ->get(route('owner.stok'))
            ->assertRedirect(route('kasir.beranda'));

        $this->actingAs($kasir)
            ->get(route('owner.stok.opname'))
            ->assertRedirect(route('kasir.beranda'));
    }

    /** Stok tenant lain tidak boleh terbawa ke daftar, sekalipun outletnya sendiri yang dibuka. */
    public function test_daftar_stok_tidak_membawa_barang_tenant_lain(): void
    {
        $milikSendiri = $this->buatProduk('Kerupuk Sendiri');
        $this->buatStok($milikSendiri, jumlah: 3, minimum: 10);

        $lain = $this->buatTenant('Warung Sebelah');
        $outletLain = $this->buatOutlet($lain, 'Outlet Sebelah');

        $this->konteks()->forTenant($lain->getKey(), function () use ($outletLain) {
            $produkLain = Product::create([
                'nama_produk' => 'Kerupuk Sebelah',
                'harga_default' => 1000,
                'satuan' => Satuan::Pcs,
            ]);

            Stock::create([
                'outlet_id' => $outletLain->getKey(),
                'product_id' => $produkLain->getKey(),
                'jumlah_saat_ini' => 1,
                'stok_minimum' => 50,
            ]);
        });

        $komponen = Livewire::actingAs($this->owner)->test(LayarStok::class);

        $nama = collect($komponen->viewData('daftar')->items())->pluck('nama')->all();

        $this->assertSame(['Kerupuk Sendiri'], $nama);
        $this->assertSame(['Kerupuk Sendiri'], $komponen->viewData('harusBelanja')->pluck('nama')->all());
    }

    /* ── Pembantu ────────────────────────────────────────────────────────── */

    private function buatProduk(string $nama, array $atribut = []): Product
    {
        return Product::create(array_merge([
            'nama_produk' => $nama,
            'harga_default' => 2000,
            'satuan' => Satuan::Pcs,
        ], $atribut));
    }

    private function buatStok(Product|RawMaterial $item, float $jumlah, float $minimum = 0): Stock
    {
        return Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $item instanceof Product ? $item->getKey() : null,
            'raw_material_id' => $item instanceof RawMaterial ? $item->getKey() : null,
            'jumlah_saat_ini' => $jumlah,
            'stok_minimum' => $minimum,
        ]);
    }
}
