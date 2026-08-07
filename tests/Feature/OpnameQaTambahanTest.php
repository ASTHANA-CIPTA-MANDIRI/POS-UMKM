<?php

namespace Tests\Feature;

use App\Actions\Stock\AdjustStockAction;
use App\Actions\Stock\CatatOpnameAction;
use App\Actions\Stock\SusunBarisStokAction;
use App\Enums\AlasanOpname;
use App\Enums\Satuan;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Stok\Opname;
use App\Models\Produk\Product;
use App\Models\Stok\Stock;
use App\Models\Stok\StockMovement;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * QA tambahan untuk lembar opname — dijalankan TERPISAH dari OpnameStokTest supaya
 * jelas mana uji tim BE (sudah ada, hijau) dan mana yang lahir dari sesi QA ini.
 *
 * Dua cacat yang dibuktikan di sini:
 *
 * 1. Kegagalan baris di TENGAH proses simpan (bukan yang ditolak validator di depan)
 *    memang tidak membatalkan baris lain — itu benar sesuai desain — TAPI pesan yang
 *    diterima pemilik tidak menyebut nama barang mana yang gagal maupun alasannya.
 *    Requirement QA #2 secara eksplisit meminta ini dibuktikan, bukan diasumsikan.
 *
 * 2. `sistemSaatDibuka` adalah properti publik Livewire tanpa #[Locked] dan tidak
 *    pernah divalidasi ulang terhadap apa yang sungguh pernah dirender ke pengguna.
 *    Siapa pun yang bisa memanggil komponennya langsung (bukan lewat Alpine di
 *    opname.js) bisa memalsukan angka "layar menunjukkan X" yang masuk ke catatan
 *    kartu stok — walau angka SELISIH-nya sendiri tetap benar dan aman.
 */
class OpnameQaTambahanTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Opname QA');
        $this->outlet = $this->buatOutlet($this->tenant, 'Outlet Opname QA');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Opname QA',
            'email' => 'owner@opname-qa.test',
            'password' => 'rahasia123',
        ]);

        $this->kasir = $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Kasir Opname QA',
            'username' => 'kasiropnameqa',
            'pin_hash' => '123456',
            'outlet_id' => $this->outlet->getKey(),
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    private function buatProduk(string $nama): Product
    {
        return Product::create([
            'nama_produk' => $nama,
            'harga_default' => 2000,
            'satuan' => Satuan::Pcs,
        ]);
    }

    private function buatStok(Product $produk, float $jumlah): Stock
    {
        return Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => $jumlah,
            'stok_minimum' => 0,
        ]);
    }

    /**
     * CACAT #1: satu baris yang gagal karena persaingan (bukan yang ditolak validator
     * Livewire di depan) tidak membatalkan baris lain — tapi pemilik hanya diberi
     * ANGKA jumlah baris gagal, tanpa nama barang atau sebab.
     *
     * Skenario nyata yang disimulasikan: pemilik membuka lembar, ketik "10" (sama
     * dengan sistem) untuk si Gula — TIDAK perlu alasan karena selisihnya nol saat
     * itu. Sebelum baris Gula benar-benar diproses CatatOpnameAction (baris Kopi
     * diproses lebih dulu), seorang kasir menjual 4 Gula secara offline dan baru
     * tersinkron persis di antara dua baris ini. Saat CatatOpnameAction akhirnya
     * mengunci baris Gula, saldonya sudah 6 — selisihnya 4, dan alasan (yang tidak
     * pernah diminta ke pemilik karena syaratnya belum terlihat saat ia mengetik)
     * masih kosong. Baris Gula gagal; baris Kopi tetap tersimpan.
     *
     * Kami mensimulasikan urutan ini dengan menyisipkan penjualan (transaksi TERPISAH,
     * SUDAH commit) tepat setelah lembar membaca sistem = 10 lewat SusunBarisStokAction
     * — titik yang sama yang dipakai render() dan periksa() untuk "apa yang terlihat di
     * layar". CatatOpnameAction lalu mengunci baris Gula lewat SiapkanBarisStokAction
     * (TIDAK dipalsukan) dan melihat saldo yang SUDAH bergerak, persis seperti penjualan
     * sungguhan yang tersinkron di antara pemilik membuka lembar dan menekan simpan.
     */
    public function test_baris_gagal_di_tengah_proses_menyebut_nama_dan_sebabnya(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $stokKopi = $this->buatStok($kopi, 5);

        $gula = $this->buatProduk('Gula Pasir');
        $stokGula = $this->buatStok($gula, 10);

        // Menyisipkan penjualan SEBAGAI TRANSAKSI TERPISAH, sudah commit, tepat setelah
        // sistem "10" terbaca (satu-satunya titik yang menentukan apakah alasan
        // diwajibkan di periksa()) — bukan di dalam transaksi opname itu sendiri, supaya
        // tidak ikut ter-rollback kalau baris opname-nya nanti ditolak.
        $racy = new class($this->kasir->getKey(), $gula->getKey(), $stokGula) extends SusunBarisStokAction
        {
            private bool $sudahDipicu = false;

            public function __construct(
                private string $kasirId,
                private string $produkRacyId,
                private Stock $stokRacy,
            ) {}

            public function execute(string $outletId, string $jenis = 'semua', string $cari = ''): Collection
            {
                $baris = parent::execute($outletId, $jenis, $cari);

                if (! $this->sudahDipicu) {
                    $this->sudahDipicu = true;

                    // Penjualan offline 4 pcs Gula, disinkronkan TEPAT setelah lembar
                    // membaca sistem = 10 — pemilik tidak pernah melihat angka barunya.
                    app(AdjustStockAction::class)->execute(
                        $this->stokRacy,
                        StockMovementType::Keluar,
                        -4,
                        olehUserId: $this->kasirId,
                    );
                }

                return $baris;
            }
        };

        // Diketik dengan action ASLI (belum dibajak) — inilah yang benar-benar terjadi
        // di layar pemilik: sistem masih 10 untuk Gula, jadi tidak ada alasan yang
        // pernah diminta padanya, sama seperti kolomnya di lapangan.
        $komponen = Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$kopi->getKey(), 3)
            ->set('alasan.'.$kopi->getKey(), AlasanOpname::Rusak->value)
            ->set('fisik.'.$gula->getKey(), 10);

        // Baru SEKARANG dibajak — tepat sebelum tombol simpan ditekan, meniru
        // penjualan yang tersinkron persis di jendela antara mengetik dan menekan
        // simpan. simpan() sendiri memanggil semuaBaris() satu kali (dipakai baik
        // untuk periksa() maupun untuk menyusun $muatan), dan itulah panggilan yang
        // dibajak: nilainya sendiri tetap 10 (pemilik memang melihat itu), tapi saldo
        // ASLI di database sudah bergerak begitu panggilan itu selesai — persis
        // seperti kartu stok mengunci saldo TERBARU, bukan yang tampil di layar.
        $this->app->instance(SusunBarisStokAction::class, $racy);

        $komponen->call('simpan');

        // Kegagalan ini datang SESUDAH simpan, bukan dari periksa() di depan — jadi dulu
        // tidak ada satu pun galat yang tercatat dan kolom @error di Blade tetap bisu untuk
        // baris Gula. Sekarang sebabnya dititipkan ke $errors supaya penandanya menempel di
        // barisnya dan namanya muncul di ringkasan; yang diperiksa di bawah.
        $komponen->assertHasErrors();

        $this->assertEqualsWithDelta(3.0, (float) $stokKopi->fresh()->jumlah_saat_ini, 0.001,
            'baris yang sah (Kopi) harus tetap tersimpan walau baris lain gagal');
        $this->assertNotNull($stokKopi->fresh()->opname_terakhir_pada);

        $this->assertEqualsWithDelta(6.0, (float) $stokGula->fresh()->jumlah_saat_ini, 0.001,
            'baris Gula gagal disimpan — saldonya cuma bergerak akibat penjualan yang disisipkan, bukan opname');
        $this->assertNull($stokGula->fresh()->opname_terakhir_pada,
            'baris yang gagal tidak boleh ditandai sudah diopname');

        // Baris yang gagal DIBIARKAN terisi supaya bisa dicoba lagi — ini sudah benar.
        $this->assertSame('10', (string) $komponen->get('fisik.'.$gula->getKey()));
        // Baris yang berhasil sudah dibersihkan.
        $this->assertArrayNotHasKey($kopi->getKey(), $komponen->get('fisik'));

        // ---- Apa yang SUNGGUH diterima pemilik di layar ----
        $komponen->assertDispatched('toast');

        $toast = collect(data_get($komponen->effects, 'dispatches'))->firstWhere('name', 'toast');
        $pesanToast = (string) ($toast['params']['pesan'] ?? '');

        $this->assertStringContainsString('1 baris gagal', $pesanToast);

        /*
         * Sebabnya per baris harus sampai ke $errors, bukan cuma dihitung.
         *
         * Diperiksa di $errors dan bukan hanya di toast karena toast hilang begitu ditutup,
         * sementara baris yang gagal bisa berada di halaman lain — dengan 10 baris per
         * halaman, lembar 120 barang punya 12 halaman, jadi itu keadaan biasa. Blok
         * ringkasan galat di Blade membaca $errors dan memasangkannya dengan nama barang.
         */
        $komponen->assertHasErrors('fisik.'.$gula->getKey());

        $this->assertStringContainsString(
            'alasan',
            strtolower((string) $komponen->errors()->first('fisik.'.$gula->getKey())),
            'sebab kegagalan sudah diketahui CatatOpnameAction dan harus ikut sampai ke pemilik');

        // Sebagian baris SUDAH tersimpan, jadi penjelasnya tidak boleh berbunyi "tidak ada
        // satu baris pun yang tersimpan" — itu keterangan yang salah tentang data yang
        // sudah berubah, dan pemiliknya bisa menghitung ulang Kopi yang sudah tercatat.
        $this->assertTrue($komponen->get('sebagianTersimpan'));

        // Dan yang benar-benar terbaca di halaman: nama barangnya, bukan "Baris lain".
        $komponen->assertSee('Gula Pasir');
        $komponen->assertSee('Baris lain sudah tersimpan.');
        $komponen->assertDontSee('Tidak ada satu baris pun yang tersimpan');
    }

    /**
     * `sistemSaatDibuka` tidak bisa dipalsukan dari klien.
     *
     * Dulu properti ini publik tanpa #[Locked], dan server hanya memeriksa is_numeric() —
     * bukan "apakah angka ini pernah benar-benar ditampilkan". QA membuktikan angka 999
     * bisa dikirim lewat permintaan Livewire mentah, memotong Alpine sepenuhnya, dan angka
     * itu tercetak sebagai fakta di catatan kartu stok.
     *
     * Angka stoknya sendiri tidak pernah terpengaruh — selisihnya selalu fisik − saldo saat
     * simpan, di dalam lock. Yang rusak JEJAK AUDITNYA: satu-satunya keterangan yang
     * menjelaskan kenapa saldo bergerak selama penghitungan, bisa dikarang oleh orang yang
     * sedang dijelaskan olehnya. Jejak audit yang bisa dipalsukan pihak yang diaudit sama
     * saja dengan tidak ada.
     */
    public function test_sistem_saat_dibuka_tidak_bisa_dipalsukan_dari_klien(): void
    {
        $produk = $this->buatProduk('Susu Kental QA');
        $this->buatStok($produk, 10);

        $komponen = Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$produk->getKey(), 7)
            ->set('alasan.'.$produk->getKey(), AlasanOpname::Rusak->value);

        // Server menolak pembaruan properti terkunci, bukan menerimanya lalu mengabaikan:
        // penolakan yang terlihat lebih baik daripada nilai yang diam-diam dibuang.
        try {
            $komponen->set('sistemSaatDibuka.'.$produk->getKey(), 999);
            $this->fail('sistemSaatDibuka seharusnya terkunci dari klien');
        } catch (CannotUpdateLockedPropertyException) {
            // inilah yang diharapkan
        }
    }

    /**
     * Penjaga arah sebaliknya: menguncinya TIDAK boleh mematikan gunanya.
     *
     * Ini yang paling mudah rusak tanpa terlihat. Kalau #[Locked] membuat nilainya tidak
     * pernah terisi, catatan mutasi kehilangan angka "yang terbaca di layar", dan kartu
     * stok tidak bisa lagi menjelaskan selisih yang bukan salah penghitungnya — cacatnya
     * berpindah, bukan hilang.
     */
    public function test_angka_yang_benar_benar_dirender_tetap_masuk_catatan(): void
    {
        $produk = $this->buatProduk('Sirup Melon');
        $stok = $this->buatStok($produk, 10);

        $komponen = Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$produk->getKey(), 7);

        // Angkanya direkam server saat baris dirender, jadi harus sudah ada tanpa satu pun
        // kiriman dari klien.
        $this->assertEqualsWithDelta(
            10.0,
            (float) ($komponen->get('sistemSaatDibuka')[$produk->getKey()] ?? 0),
            0.001,
            'angka yang tampil di layar harus direkam server sendiri');

        // Penjualan menyusul SESUDAH pemilik membaca 10, sebelum ia menekan simpan.
        app(AdjustStockAction::class)->execute(
            $stok, StockMovementType::Keluar, -4, null, $this->kasir->getKey(), 'Penjualan offline menyusul',
        );

        $komponen->set('alasan.'.$produk->getKey(), AlasanOpname::Lainnya->value)
            ->set('catatan.'.$produk->getKey(), 'Dihitung ulang bersama kasir')
            ->call('simpan');

        $mutasi = StockMovement::query()->latest('id')->first();

        // Selisih tetap dihitung terhadap saldo saat simpan (7 − 6 = +1), bukan terhadap
        // angka yang terbaca di layar — itu aturan yang tidak boleh bergeser.
        $this->assertEqualsWithDelta(1.0, (float) $mutasi->jumlah, 0.001);

        // Dan catatannya memuat KEDUA angka, supaya selisihnya bisa dijelaskan.
        $this->assertStringContainsString('10', (string) $mutasi->catatan);
        $this->assertStringContainsString('6', (string) $mutasi->catatan);
    }

    /* ── Keamanan tenant & outlet — DIBUKTIKAN AMAN, bukan diasumsikan ────── */

    /**
     * DIBUKTIKAN AMAN: mengirim kunci baris (`fisik.<id>`) milik produk TENANT LAIN
     * tidak membuat mutasi apa pun pada tenant itu.
     *
     * Kuncinya adalah TenantScope pada Product/RawMaterial/Stock (lewat BelongsToTenant)
     * + TerikatTenant di komponen Opname: produk tenant lain tidak pernah muncul di
     * semuaBaris(), jadi periksa() langsung menolaknya sebagai "barang ini tidak ada
     * lagi di daftar stok outlet ini" — SEBELUM CatatOpnameAction sempat dipanggil sama
     * sekali. Tidak ada baris pun (termasuk baris SAH milik tenant sendiri di request
     * yang sama) yang tersimpan, karena validasi berjalan atas SELURUH lembar dulu.
     */
    public function test_kunci_baris_milik_produk_tenant_lain_ditolak_dan_tidak_membuat_mutasi(): void
    {
        $tenantLain = $this->buatTenant('Toko Tetangga');
        $outletLain = $this->buatOutlet($tenantLain, 'Outlet Tetangga');

        $produkTenantLain = $this->konteks()->forTenant($tenantLain->getKey(), function () use ($outletLain) {
            $produk = Product::create([
                'nama_produk' => 'Barang Tetangga',
                'harga_default' => 5000,
                'satuan' => Satuan::Pcs,
            ]);

            Stock::create([
                'outlet_id' => $outletLain->getKey(),
                'product_id' => $produk->getKey(),
                'jumlah_saat_ini' => 50,
                'stok_minimum' => 0,
            ]);

            return $produk;
        });

        // Baris sah milik tenant sendiri, dikirim BERSAMAAN dalam request yang sama.
        $produkSendiri = $this->buatProduk('Barang Sendiri');
        $stokSendiri = $this->buatStok($produkSendiri, 10);

        Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$produkSendiri->getKey(), 8)
            ->set('alasan.'.$produkSendiri->getKey(), AlasanOpname::Rusak->value)
            // Percobaan: kunci baris milik produk TENANT LAIN, dikirim langsung lewat
            // Livewire (bukan lewat form yang sungguhan dirender — form tenant A tidak
            // pernah menampilkan baris ini sama sekali).
            ->set('fisik.'.$produkTenantLain->getKey(), 999)
            ->call('simpan')
            ->assertHasErrors('fisik.'.$produkTenantLain->getKey());

        // Tidak ada mutasi SAMA SEKALI — termasuk baris sah milik tenant sendiri, sesuai
        // aturan "satu baris gagal validasi menahan SELURUH lembar" yang sudah diuji di
        // OpnameStokTest. Yang penting dibuktikan di sini: baris tenant lain tidak lolos
        // diam-diam.
        $this->assertSame(0, StockMovement::count());
        $this->assertEqualsWithDelta(10.0, (float) $stokSendiri->fresh()->jumlah_saat_ini, 0.001);

        $stokTetangga = $this->konteks()->forTenant($tenantLain->getKey(),
            fn () => Stock::where('product_id', $produkTenantLain->getKey())->sole());
        $this->assertEqualsWithDelta(50.0, (float) $stokTetangga->jumlah_saat_ini, 0.001,
            'stok tenant lain tidak boleh bergerak sama sekali akibat request tenant A');
    }

    /**
     * DIBUKTIKAN AMAN: owner tenant A tidak bisa membuka/menyimpan lembar opname untuk
     * outlet yang bukan milik tenantnya sendiri, walau outletId dikirim langsung lewat
     * query string / properti Livewire (bukan dipilih dari <select> yang sungguhan
     * dirender — pilihan itu hanya memuat outlet tenant sendiri).
     */
    public function test_owner_tidak_bisa_memilih_outlet_milik_tenant_lain(): void
    {
        $tenantLain = $this->buatTenant('Toko Tetangga Outlet');
        $outletLain = $this->buatOutlet($tenantLain, 'Outlet Tetangga Outlet');

        // abort_unless(403) di Opname::outletTerpakai() dirender oleh Livewire menjadi
        // respons HTTP 403 (bukan dilempar sebagai exception PHP ke pemanggil test) —
        // makanya diperiksa lewat assertForbidden(), bukan expectException().
        Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('outletId', $outletLain->getKey())
            ->assertForbidden();
    }
}
