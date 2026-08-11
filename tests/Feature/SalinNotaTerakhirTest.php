<?php

namespace Tests\Feature;

use App\Actions\Pembelian\CatatPembelianAction;
use App\Actions\Pembelian\SalinNotaTerakhirAction;
use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Pembelian\PembelianBaru;
use App\Models\Pembelian\PurchaseOrder;
use App\Models\Produk\Product;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * "Sama seperti belanja terakhir" — pengurang pengetikan terbesar di aplikasi ini.
 *
 * Warung kulakan dua sampai tiga kali sepekan dari grosir yang sama, dan barangnya hampir
 * sama tiap kali. Mengetik ulang empat puluh baris tiap kali adalah pekerjaan manual terbesar
 * yang tersisa — dan pekerjaan manual terbesar adalah alasan orang berhenti mencatat lalu
 * kembali ke buku.
 *
 * Yang dijaga paling keras:
 *
 *  - MENGISI, BUKAN MENYIMPAN. Harga grosir berubah tiap pekan; nota yang tersimpan otomatis
 *    dengan harga pekan lalu menaruh angka yang salah ke hitungan modal SELURUH barang.
 *  - Nota terakhir DI CABANG YANG SAMA. Daftar cabang lain memaksa orangnya mengosongkan
 *    empat puluh kotak — lebih lama daripada mengetik dari nol.
 *  - TANGGAL TIDAK IKUT DISALIN. Nota bertanggal lama membuat stok masuk ke hari yang sudah
 *    lewat, dan laporan hari itu berubah sesudah ditutup.
 *  - Barang yang sudah dihapus DILEWATI dan DIHITUNG. Isian yang diam-diam hilang membuat
 *    orangnya menyimpan nota yang kurang satu barang tanpa pernah tahu.
 */
class SalinNotaTerakhirTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Kelontong Kulakan');
        $this->outlet = $this->buatOutlet($this->tenant, 'Outlet Utama');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik',
            'email' => 'owner@kulakan.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    private function aksi(): SalinNotaTerakhirAction
    {
        return app(SalinNotaTerakhirAction::class);
    }

    private function buatProduk(string $nama, float $beli): Product
    {
        return Product::create([
            'nama_produk' => $nama,
            'harga_default' => $beli * 1.3,
            'harga_beli' => $beli,
            'satuan' => Satuan::Pcs,
        ]);
    }

    /**
     * @param  array<int, array{Product, float, float}>  $baris
     */
    private function buatNota(array $baris, ?Outlet $di = null, ?string $tanggal = null, string $dari = 'Grosir Pak Slamet'): PurchaseOrder
    {
        return app(CatatPembelianAction::class)->execute($di ?? $this->outlet, $this->owner, [
            'beli_dari' => $dari,
            'tanggal' => $tanggal ?? now()->subWeek()->toDateString(),
            'sudah_datang' => true,
            'baris' => array_map(fn (array $b) => [
                'product_id' => $b[0]->getKey(),
                'raw_material_id' => null,
                'qty_beli' => $b[1],
                'harga_satuan' => $b[2],
            ], $baris),
        ]);
    }

    /* ── Yang disalin ────────────────────────────────────────────────────── */

    #[Test]
    public function barang_jumlah_dan_harga_nota_terakhir_tersalin(): void
    {
        $beras = $this->buatProduk('Beras Premium', 65000);
        $gula = $this->buatProduk('Gula Pasir', 14000);

        $this->buatNota([[$beras, 10, 65000], [$gula, 5, 14000]]);

        $salinan = $this->aksi()->untuk($this->outlet->getKey());

        $this->assertSame('10', $salinan['jumlah'][$beras->getKey()]);
        $this->assertSame('65000', $salinan['harga'][$beras->getKey()]);
        $this->assertSame('5', $salinan['jumlah'][$gula->getKey()]);
        $this->assertSame('Grosir Pak Slamet', $salinan['beliDari']);
    }

    #[Test]
    public function jumlah_bulat_tidak_ikut_membawa_nol_di_belakang_koma(): void
    {
        /*
         * Kolomnya decimal(15,3), jadi qty 10 keluar sebagai "10.000". Ditaruh apa adanya di
         * kotak jumlah, angka itu terbaca SEPULUH RIBU — dan nota tersimpan dengan sepuluh
         * ribu karung beras.
         */
        $beras = $this->buatProduk('Beras Premium', 65000);
        $this->buatNota([[$beras, 10, 65000]]);

        $this->assertSame('10', $this->aksi()->untuk($this->outlet->getKey())['jumlah'][$beras->getKey()]);
    }

    #[Test]
    public function jumlah_berdesimal_tetap_dipertahankan(): void
    {
        // Pagar untuk uji di atas: pembulatan yang berlaku untuk semua akan mengubah 0,25 kg
        // lele jadi 0 kg, dan notanya ditolak "jumlah harus lebih dari nol".
        $lele = $this->buatProduk('Lele Segar', 32000);
        $this->buatNota([[$lele, 2.5, 32000]]);

        $this->assertSame('2,5', $this->aksi()->untuk($this->outlet->getKey())['jumlah'][$lele->getKey()]);
    }

    #[Test]
    public function yang_diambil_nota_paling_baru_bukan_yang_pertama(): void
    {
        $beras = $this->buatProduk('Beras Premium', 65000);
        $gula = $this->buatProduk('Gula Pasir', 14000);

        $this->buatNota([[$beras, 10, 60000]], tanggal: now()->subMonth()->toDateString());
        $this->buatNota([[$gula, 3, 14000]], tanggal: now()->subDay()->toDateString());

        $salinan = $this->aksi()->untuk($this->outlet->getKey());

        $this->assertArrayHasKey($gula->getKey(), $salinan['jumlah']);
        $this->assertArrayNotHasKey($beras->getKey(), $salinan['jumlah'], 'nota bulan lalu bukan "belanja terakhir"');
    }

    /* ── Cabang ──────────────────────────────────────────────────────────── */

    #[Test]
    public function nota_cabang_lain_tidak_ikut_tersalin(): void
    {
        /*
         * Barang yang dikulak cabang A bisa berbeda jauh dari cabang B. Mengisi formulir
         * cabang B dengan daftar cabang A membuat orangnya harus mengosongkan empat puluh
         * kotak — lebih lama daripada mengetik dari nol.
         */
        $cabangDua = $this->buatOutlet($this->tenant, 'Cabang Dua');
        $beras = $this->buatProduk('Beras Premium', 65000);
        $gula = $this->buatProduk('Gula Pasir', 14000);

        $this->buatNota([[$beras, 10, 65000]], di: $this->outlet, tanggal: now()->subWeek()->toDateString());
        $this->buatNota([[$gula, 99, 14000]], di: $cabangDua, tanggal: now()->subDay()->toDateString());

        $salinan = $this->aksi()->untuk($this->outlet->getKey());

        $this->assertArrayHasKey($beras->getKey(), $salinan['jumlah']);
        $this->assertArrayNotHasKey($gula->getKey(), $salinan['jumlah']);
    }

    /* ── Yang TIDAK disalin ──────────────────────────────────────────────── */

    #[Test]
    public function tanggal_nota_lama_tidak_ikut_ke_isian(): void
    {
        /*
         * Nota baru bertanggal hari ini. Tanggal lama membuat stok masuk ke hari yang sudah
         * lewat, dan laporan hari itu berubah sesudah ditutup — orang yang sudah mencocokkan
         * uangnya kemarin mendapati angkanya berbeda hari ini.
         */
        $beras = $this->buatProduk('Beras Premium', 65000);
        $this->buatNota([[$beras, 10, 65000]]);

        $layar = Livewire::actingAs($this->owner)->test(PembelianBaru::class, ['outlet' => $this->outlet->getKey()])
            ->call('ulangiTerakhir');

        $layar->assertSet('tanggal', now()->toDateString());
    }

    #[Test]
    public function potongan_dan_ongkos_kirim_tidak_ikut_tersalin(): void
    {
        // Keduanya kesepakatan sekali jalan; menyalinnya berarti memasukkan potongan yang
        // tidak diberikan siapa pun ke dalam hitungan.
        $beras = $this->buatProduk('Beras Premium', 65000);
        $this->buatNota([[$beras, 10, 65000]]);

        $salinan = $this->aksi()->untuk($this->outlet->getKey());

        $this->assertArrayNotHasKey('diskon', $salinan);
        $this->assertArrayNotHasKey('ongkosKirim', $salinan);
    }

    /* ── Barang yang sudah hilang ────────────────────────────────────────── */

    #[Test]
    public function baris_tanpa_barang_dilewati_dan_dihitung(): void
    {
        /*
         * Barang bisa dihapus sesudah nota lama dibuat. Isian yang diam-diam hilang membuat
         * orangnya menyimpan nota yang kurang satu barang tanpa pernah tahu — jumlahnya
         * dikembalikan supaya layar bisa mengatakannya.
         */
        $beras = $this->buatProduk('Beras Premium', 65000);
        $gula = $this->buatProduk('Gula Pasir', 14000);

        $nota = $this->buatNota([[$beras, 10, 65000], [$gula, 5, 14000]]);

        // Kunci barisnya dikosongkan, meniru baris yang barangnya tidak bisa ditunjuk lagi.
        $nota->items()->where('product_id', $gula->getKey())
            ->update(['product_id' => null, 'raw_material_id' => null]);

        $salinan = $this->aksi()->untuk($this->outlet->getKey());

        $this->assertCount(1, $salinan['jumlah']);
        $this->assertSame(1, $salinan['dilewati']);
    }

    #[Test]
    public function belum_ada_nota_sama_sekali_mengembalikan_null(): void
    {
        $this->assertNull($this->aksi()->untuk($this->outlet->getKey()));
    }

    /* ── Lewat layar ─────────────────────────────────────────────────────── */

    #[Test]
    public function tombolnya_mengisi_formulir_tanpa_menyimpan_apa_pun(): void
    {
        /*
         * INTI seluruh rancangan ini. Harga grosir berubah tiap pekan; yang tersimpan
         * otomatis dengan harga pekan lalu menaruh angka yang salah ke dalam hitungan modal
         * seluruh barang. Orangnya wajib melihat dan bisa mengubah dulu.
         */
        $beras = $this->buatProduk('Beras Premium', 65000);
        $this->buatNota([[$beras, 10, 65000]]);

        $sebelum = PurchaseOrder::count();

        Livewire::actingAs($this->owner)->test(PembelianBaru::class, ['outlet' => $this->outlet->getKey()])
            ->call('ulangiTerakhir')
            ->assertSet('jumlah.'.$beras->getKey(), '10')
            ->assertSet('harga.'.$beras->getKey(), '65000');

        $this->assertSame($sebelum, PurchaseOrder::count(), 'mengisi, bukan menyimpan');
    }

    #[Test]
    public function isian_yang_sudah_diketik_ditimpa_bukan_ditambahkan(): void
    {
        /*
         * Menggabungkan berarti jumlah yang sudah diketik orangnya bertambah diam-diam
         * dengan jumlah nota lama — dan yang berubah adalah angka barang yang masuk ke stok.
         * Menimpa lebih jujur: apa yang terlihat di layar itulah yang akan tersimpan.
         */
        $beras = $this->buatProduk('Beras Premium', 65000);
        $this->buatNota([[$beras, 10, 65000]]);

        Livewire::actingAs($this->owner)->test(PembelianBaru::class, ['outlet' => $this->outlet->getKey()])
            ->set('jumlah.'.$beras->getKey(), '3')
            ->call('ulangiTerakhir')
            ->assertSet('jumlah.'.$beras->getKey(), '10');
    }

    #[Test]
    public function nama_tempat_belanja_yang_sudah_diketik_tidak_ditimpa(): void
    {
        // Yang sudah diketik orangnya lebih tahu daripada nota lama — ia mungkin memang
        // sedang kulakan di grosir yang berbeda hari ini.
        $beras = $this->buatProduk('Beras Premium', 65000);
        $this->buatNota([[$beras, 10, 65000]]);

        Livewire::actingAs($this->owner)->test(PembelianBaru::class, ['outlet' => $this->outlet->getKey()])
            ->set('beliDari', 'Grosir Bu Sri')
            ->call('ulangiTerakhir')
            ->assertSet('beliDari', 'Grosir Bu Sri');
    }

    #[Test]
    public function tombolnya_mengabarkan_nota_mana_yang_disalin(): void
    {
        // Tombol yang mengisi empat puluh kotak sekaligus tanpa memberi tahu isinya dari mana
        // tidak akan ditekan dua kali oleh siapa pun.
        $beras = $this->buatProduk('Beras Premium', 65000);
        $nota = $this->buatNota([[$beras, 10, 65000]]);

        Livewire::actingAs($this->owner)->test(PembelianBaru::class, ['outlet' => $this->outlet->getKey()])
            ->call('ulangiTerakhir')
            ->assertDispatched('toast', fn (string $n, array $d) => str_contains($d['pesan'], $nota->nomor_po)
                && str_contains($d['pesan'], 'periksa dulu'));
    }

    #[Test]
    public function tanpa_nota_sebelumnya_layarnya_mengabarkan_bukan_diam(): void
    {
        // Tombolnya memang tidak dirender saat belum ada nota, tapi muatan Livewire bisa
        // dikirim tanpa melewati layar — dan diam adalah jawaban yang tidak bisa ditafsirkan.
        Livewire::actingAs($this->owner)->test(PembelianBaru::class, ['outlet' => $this->outlet->getKey()])
            ->call('ulangiTerakhir')
            ->assertDispatched('toast', fn (string $n, array $d) => str_contains($d['pesan'], 'Belum ada nota'));
    }
}
