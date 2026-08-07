<?php

namespace Tests\Feature;

use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Pembelian\Pembelian;
use App\Livewire\Pages\Owner\Pembelian\PembelianBaru;
use App\Models\Pembelian\PurchaseOrder;
use App\Models\Produk\Product;
use App\Models\Stok\StockMovement;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataPembelian;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Isolasi merchant & kunci outlet di layar Pembelian.
 *
 * Kunci outletnya menutup cacat yang sama dengan lembar hitung stok, dan penyebabnya juga
 * sama: kunci baris adalah id barang, dan barang bersifat TENANT — barang yang sama punya
 * kunci yang sama di semua cabang. Angka yang diketik untuk Cabang A "cocok" sempurna di
 * Cabang B, jadi dropdown yang diganti sebelum tombol simpan ditekan akan memasukkan
 * seluruh belanjaan ke cabang yang salah. Tidak ada galat, tidak ada penolakan; hanya rak
 * Cabang B yang menurut catatan bertambah 24 pcs yang tidak pernah sampai ke sana, dan rak
 * Cabang A yang tetap kosong sambil pemiliknya yakin sudah mencatat.
 */
class PembelianTenantOutletTest extends TestCase
{
    use MembuatDataPembelian, MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $cabangA;

    private Outlet $cabangB;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Dua Cabang Belanja');

        // Namanya diurutkan supaya outlet bawaan (orderBy outlet_name) selalu A.
        $this->cabangA = $this->buatOutlet($this->tenant, 'Cabang A Pusat');
        $this->cabangB = $this->buatOutlet($this->tenant, 'Cabang B Ruko');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Dua Cabang',
            'email' => 'owner@duacabangbelanja.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    /* ── Isolasi merchant ────────────────────────────────────────────────── */

    public function test_nota_tenant_lain_tidak_terlihat_di_daftar(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $milikSendiri = $this->catatNota($this->cabangA, $this->owner, ['baris' => [$this->baris($kopi, 5, 1500)]]);

        $tetangga = $this->buatTenant('Warung Tetangga');
        $outletTetangga = $this->buatOutlet($tetangga, 'Outlet Tetangga');
        $ownerTetangga = $this->buatUser($tetangga, UserRole::Owner, [
            'name' => 'Pemilik Tetangga',
            'email' => 'owner@tetanggabelanja.test',
            'password' => 'rahasia123',
        ]);

        $notaTetangga = $this->konteks()->forTenant($tetangga->getKey(), function () use ($outletTetangga, $ownerTetangga) {
            $produk = Product::create(['nama_produk' => 'Kopi Tetangga', 'harga_default' => 2000, 'satuan' => Satuan::Pcs]);

            return $this->catatNota($outletTetangga, $ownerTetangga, ['baris' => [$this->baris($produk, 5, 1500)]]);
        });

        $this->konteks()->setTenant($this->tenant->getKey());

        $daftar = Livewire::actingAs($this->owner)->test(Pembelian::class)->viewData('daftar');
        $id = collect($daftar->items())->pluck('id')->all();

        $this->assertSame([$milikSendiri->getKey()], $id);
        $this->assertNotContains($notaTetangga->getKey(), $id);

        // Dua nota memang ada di basis data — jadi yang diuji benar-benar penyaringannya,
        // bukan kebetulan tidak ada datanya.
        $this->assertSame(2, PurchaseOrder::withoutGlobalScopes()->count());
    }

    /**
     * Nota merchant lain tidak bisa dibatalkan lewat id yang ditebak.
     *
     * Pembatalan MENGUBAH stok. Kalau id-nya cukup untuk mengeksekusinya, satu merchant
     * bisa mengosongkan rak merchant lain dari layarnya sendiri.
     */
    public function test_nota_tenant_lain_tidak_bisa_dibatalkan(): void
    {
        $tetangga = $this->buatTenant('Warung Tetangga Batal');
        $outletTetangga = $this->buatOutlet($tetangga, 'Outlet Tetangga Batal');
        $ownerTetangga = $this->buatUser($tetangga, UserRole::Owner, [
            'name' => 'Pemilik Tetangga Batal',
            'email' => 'owner@tetanggabatal.test',
            'password' => 'rahasia123',
        ]);

        [$notaTetangga, $produkTetangga] = $this->konteks()->forTenant($tetangga->getKey(), function () use ($outletTetangga, $ownerTetangga) {
            $produk = Product::create(['nama_produk' => 'Gula Tetangga', 'harga_default' => 16000, 'satuan' => Satuan::Kg]);

            return [$this->catatNota($outletTetangga, $ownerTetangga, ['baris' => [$this->baris($produk, 8, 14000)]]), $produk];
        });

        $this->konteks()->setTenant($this->tenant->getKey());

        Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->call('batalkan', $notaTetangga->getKey());

        $this->assertSame('diterima', $notaTetangga->fresh()->status->value,
            'nota merchant lain tidak boleh berubah status');

        $this->konteks()->setTenant($tetangga->getKey());
        $this->assertEqualsWithDelta(8.0, $this->saldo($outletTetangga, $produkTetangga), 0.001,
            'stok merchant lain tidak boleh tersentuh');
    }

    /* ── Gerbang outlet ──────────────────────────────────────────────────── */

    public function test_outlet_tenant_lain_ditolak_403(): void
    {
        $lain = $this->buatTenant('Warung Sebelah Belanja');
        $outletLain = $this->buatOutlet($lain, 'Outlet Sebelah');

        // Lewat URL — jalur yang paling mudah dicoba orang.
        $this->actingAs($this->owner)
            ->get(route('owner.pembelian.baru', ['outlet' => $outletLain->getKey()]))
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->get(route('owner.pembelian', ['outlet' => $outletLain->getKey()]))
            ->assertForbidden();

        // Lewat properti Livewire — <select> hanya memuat outlet sendiri, jadi jalur ini
        // hanya bisa ditempuh dengan menyusun muatannya sendiri. Justru itu yang dijaga.
        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('outletId', $outletLain->getKey())
            ->assertForbidden();
    }

    /** Pembandingnya, supaya 403 di atas bukan karena rutenya yang rusak. */
    public function test_outlet_sendiri_di_url_bisa_dibuka(): void
    {
        $this->actingAs($this->owner)
            ->get(route('owner.pembelian.baru', ['outlet' => $this->cabangB->getKey()]))
            ->assertOk();
    }

    /**
     * Manager outlet: nilai outlet dari klien diabaikan sama sekali.
     *
     * Bukan sekadar "dropdown-nya disembunyikan" — nilainya bisa dikirim langsung ke
     * properti. Untuk peran ini outletTerpakai() selalu mengembalikan outletnya sendiri,
     * jadi barang yang ia catat tidak pernah bisa mendarat di cabang lain.
     */
    public function test_manager_outlet_selalu_memakai_outletnya_sendiri(): void
    {
        $manager = $this->buatUser($this->tenant, UserRole::ManagerOutlet, [
            'name' => 'Manager Cabang A',
            'email' => 'manager@duacabangbelanja.test',
            'password' => 'rahasia123',
            'outlet_id' => $this->cabangA->getKey(),
        ]);

        $this->assertNotNull($manager->scopedOutletId(), 'prasyarat: perannya memang terkunci satu outlet');

        $kopi = $this->buatProduk('Kopi Sachet');

        $komponen = Livewire::actingAs($manager)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), 20)
            ->set('harga.'.$kopi->getKey(), 1500);

        $this->assertSame([], $komponen->viewData('outletTersedia'), 'dropdown outlet memang tidak dirender untuk peran ini');

        // Nilai dari klien diabaikan, jadi menyetelnya tidak boleh menggagalkan penyimpanan.
        $komponen->set('outletId', $this->cabangB->getKey())
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(20.0, $this->saldo($this->cabangA, $kopi), 0.001);
        $this->assertNull($this->saldo($this->cabangB, $kopi));
        $this->assertSame($this->cabangA->getKey(), PurchaseOrder::query()->sole()->outlet_id);
    }

    /* ── Kunci outlet ────────────────────────────────────────────────────── */

    /**
     * Nota tersimpan ke cabang tempat baris PERTAMA diketik, bukan ke cabang yang kebetulan
     * terpilih saat tombol simpan ditekan.
     *
     * Lapisan ini wajib ada di simpan() walau updatedOutletId() juga menolak: `outletId`
     * ber-#[Url], jadi tombol Back peramban mengubahnya lewat popstate tanpa melewati
     * updatedOutletId() sama sekali.
     */
    public function test_nota_tersimpan_ke_outlet_tempat_baris_pertama_diketik_bukan_dropdown_saat_simpan(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $komponen = Livewire::actingAs($this->owner)->test(PembelianBaru::class);

        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletId'));
        $this->assertNull($komponen->get('outletTerkunci'), 'nota kosong tidak mengunci apa pun');

        $komponen->set('jumlah.'.$kopi->getKey(), 20)
            ->set('harga.'.$kopi->getKey(), 1500);

        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletTerkunci'),
            'baris pertama yang diketik mengunci nota ke cabang tempat ia diketik');

        // Pergantian ditolak: layar tetap menampilkan cabang tempat notanya diketik.
        $komponen->set('outletId', $this->cabangB->getKey());

        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletId'));

        $komponen->call('simpan')->assertHasNoErrors();

        $this->assertEqualsWithDelta(20.0, $this->saldo($this->cabangA, $kopi), 0.001);
        $this->assertNull($this->saldo($this->cabangB, $kopi),
            'tidak boleh ada satu pun barang yang mendarat di cabang yang salah');
        $this->assertSame($this->cabangA->getKey(), PurchaseOrder::query()->sole()->outlet_id);
        $this->assertSame(1, StockMovement::query()->count());
    }

    /**
     * Penolakan pindah cabang TIDAK boleh memindahkan halaman.
     *
     * Dengan 10 baris per halaman, warung 40 barang punya 4 halaman. Pemilik yang salah
     * menyentuh dropdown saat berada di halaman 2 lalu terlempar ke halaman 1 dihukum untuk
     * kesalahan yang sudah ditolak — dan nilai yang sudah diketik ada di halaman lain.
     */
    public function test_mengganti_outlet_saat_nota_sudah_berisi_baris_ditolak_dan_tidak_mereset_halaman(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->buatProduk('Barang '.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $penanda = $this->buatProduk('Zeta Penanda');

        $komponen = Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$penanda->getKey(), 3)
            ->set('harga.'.$penanda->getKey(), 1000)
            ->call('gotoPage', 2);

        $this->assertSame(2, $komponen->viewData('daftar')->currentPage());

        $komponen->set('outletId', $this->cabangB->getKey());

        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletId'), 'pergantiannya memang ditolak');
        $this->assertSame($this->cabangB->getKey(), $komponen->get('outletDiminta'),
            'permintaannya diingat supaya pemilik bisa memilih buang-lalu-pindah');
        $this->assertSame(2, $komponen->viewData('daftar')->currentPage(),
            'penolakan tidak boleh melempar pemiliknya ke halaman 1');

        // Angka yang sudah diketik tetap utuh.
        $this->assertSame(3, (int) $komponen->get('jumlah.'.$penanda->getKey()));
    }

    /** Buang-lalu-pindah: satu-satunya jalur yang membuang isian, dan ia disebut namanya. */
    public function test_buang_lalu_pindah_membuang_isian_dan_memindahkan_cabang(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $komponen = Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), 20)
            ->set('harga.'.$kopi->getKey(), 1500)
            ->call('pindahOutlet', $this->cabangB->getKey());

        $this->assertSame($this->cabangB->getKey(), $komponen->get('outletId'));
        $this->assertNull($komponen->get('outletTerkunci'));
        $this->assertSame([], $komponen->get('jumlah'));
        $this->assertSame(0, PurchaseOrder::query()->count());
    }

    /** Buang-lalu-pindah ke outlet merchant lain tetap 403, dan isiannya tidak dibuang. */
    public function test_buang_lalu_pindah_ke_outlet_tenant_lain_ditolak(): void
    {
        $lain = $this->buatTenant('Warung Sebelah Pindah');
        $outletLain = $this->buatOutlet($lain, 'Outlet Sebelah Pindah');

        $kopi = $this->buatProduk('Kopi Sachet');

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), 20)
            ->call('pindahOutlet', $outletLain->getKey())
            ->assertForbidden();
    }

    /** Nota kosong bebas pindah cabang — belum ada pekerjaan yang bisa hilang. */
    public function test_nota_kosong_bebas_pindah_cabang(): void
    {
        $komponen = Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('outletId', $this->cabangB->getKey());

        $this->assertSame($this->cabangB->getKey(), $komponen->get('outletId'));
        $this->assertNull($komponen->get('outletTerkunci'));
    }
}
