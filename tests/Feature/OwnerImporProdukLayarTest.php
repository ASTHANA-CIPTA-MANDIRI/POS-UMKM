<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Produk\ImporProduk as LayarImpor;
use App\Models\Produk\Product;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Layar impor produk — unggah, LIHAT DULU, baru simpan.
 *
 * Aturan pembacaan berkasnya dijaga ImporProdukTest dan BacaCsvTest. Berkas ini menjaga apa
 * yang cuma bisa salah di LAYAR:
 *
 *  - PRATINJAU YANG DIAM-DIAM MENYIMPAN. Cacat paling mahal di sini: pemilik menekan "Batal"
 *    lalu mendapati 300 barang sudah masuk, sebagian sudah dipakai transaksi.
 *  - BERKAS SEMENTARA YANG TERTINGGAL. Isinya daftar harga beli seluruh barang, dan tidak
 *    ada satu pun layar yang memperlihatkan berkas itu masih ada.
 *  - BERKAS DI FOLDER PUBLIK. Satu tautan membocorkan harga beli beserta seluruh katalog.
 */
class OwnerImporProdukLayarTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenant = $this->buatTenant('Kelontong Impor');
        $this->outlet = $this->buatOutlet($this->tenant, 'Outlet Utama');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik',
            'email' => 'owner@layar-impor.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    private function layar(): Testable
    {
        return Livewire::actingAs($this->owner)->test(LayarImpor::class);
    }

    private function berkas(string $isi, string $nama = 'produk.csv'): UploadedFile
    {
        // Berkas berisi SUNGGUHAN, bukan UploadedFile::fake()->create() yang isinya kosong.
        // Pelajaran dari lampiran nota: berkas palsu tanpa isi lolos uji yang tidak pernah
        // membaca isinya, lalu fiturnya gagal pada berkas pertama yang sungguhan.
        return UploadedFile::fake()->createWithContent($nama, $isi);
    }

    /* ── Pratinjau ───────────────────────────────────────────────────────── */

    #[Test]
    public function mengunggah_berkas_menampilkan_pratinjau_tanpa_menyimpan_apa_pun(): void
    {
        $this->layar()
            ->set('berkas', $this->berkas("nama,harga\nNasi Goreng,15000\nEs Teh,5000\n"))
            ->assertHasNoErrors();

        // Inti seluruh rancangan layar ini: pratinjau adalah kabar, bukan perintah.
        $this->assertSame(0, Product::count());
    }

    #[Test]
    public function pratinjau_memisahkan_yang_baru_dari_yang_akan_ditimpa(): void
    {
        Product::create(['nama_produk' => 'Beras Lama', 'sku' => 'BRS-5', 'harga_default' => 70000]);

        $layar = $this->layar()
            ->set('berkas', $this->berkas("nama,harga,sku\nBeras Premium,72000,BRS-5\nGula,14000,\n"));

        // "Diperbarui" berarti HARGA BARANG YANG SUDAH ADA akan berubah — keputusan yang
        // berbeda sifatnya dari menambah barang baru, jadi angkanya tidak boleh digabung.
        $layar->assertSee('Diperbarui')->assertSee('Baru');
    }

    #[Test]
    public function kolom_wajib_yang_hilang_dikabarkan_dan_tombol_simpan_mati(): void
    {
        $this->layar()
            ->set('berkas', $this->berkas("nama,keterangan\nNasi Goreng,enak\n"))
            ->assertSee('tidak ditemukan di berkasnya')
            ->call('simpan');

        $this->assertSame(0, Product::count());
    }

    #[Test]
    public function baris_yang_dilewati_disebut_berikut_nomor_dan_sebabnya(): void
    {
        // Penolakan tanpa nomor baris membuat pemilik memeriksa 300 baris satu per satu.
        $this->layar()
            ->set('berkas', $this->berkas("nama,harga\nNasi Goreng,15000\nEs Teh,bukan angka\n"))
            ->assertSee('Baris 3')
            ->assertSee('Harganya tidak terbaca');
    }

    /* ── Menyimpan ───────────────────────────────────────────────────────── */

    #[Test]
    public function simpan_memasukkan_barangnya_dan_mengabarkan_hasilnya(): void
    {
        $this->layar()
            ->set('berkas', $this->berkas("nama,harga\nNasi Goreng,15000\nEs Teh,5000\n"))
            ->call('simpan')
            ->assertDispatched('toast', fn (string $n, array $d) => str_contains($d['pesan'], '2 barang baru masuk'));

        $this->assertSame(2, Product::count());
        $this->assertSame(15000.0, (float) Product::where('nama_produk', 'Nasi Goreng')->firstOrFail()->harga_default);
    }

    #[Test]
    public function properti_pratinjau_tidak_bisa_disetel_dari_klien(): void
    {
        /*
         * Lapis PERTAMA: #[Locked]. Muatan Livewire yang mencoba mengganti isi pratinjau
         * ditolak Livewire sendiri — dan yang bisa diganti di situ adalah harga barang.
         */
        $layar = $this->layar()->set('berkas', $this->berkas("nama,harga\nNasi Goreng,15000\n"));

        $this->expectExceptionMessageMatches('/locked property/');

        $layar->set('pratinjau', ['kolomHilang' => [], 'siap' => [], 'ditolak' => [], 'terpotong' => false]);
    }

    #[Test]
    public function simpan_membaca_ulang_berkasnya_bukan_kesimpulan_lama(): void
    {
        /*
         * Lapis KEDUA, dan inilah yang sebenarnya menahan: simpan() memanggil periksa() lagi
         * dari ISI BERKAS, bukan mengerjakan hasil pratinjau yang tersimpan di properti.
         *
         * Dibuktikan dengan mengganti berkasnya SESUDAH pratinjau dibuat. Kalau layarnya
         * mengerjakan pratinjau, yang tersimpan adalah "Nasi Goreng"; kalau ia membaca
         * berkasnya lagi, yang tersimpan adalah "Es Teh". Uji ini tetap berarti seandainya
         * #[Locked] suatu hari terlepas — dan justru itu gunanya dua lapis.
         */
        $layar = $this->layar()->set('berkas', $this->berkas("nama,harga\nNasi Goreng,15000\n"));

        Storage::disk('local')->put($layar->get('jalurSementara'), "nama,harga\nEs Teh,5000\n");

        $layar->call('simpan');

        $this->assertSame(1, Product::count());
        $this->assertSame('Es Teh', Product::firstOrFail()->nama_produk);
    }

    #[Test]
    public function batal_tidak_menyimpan_apa_pun(): void
    {
        $this->layar()
            ->set('berkas', $this->berkas("nama,harga\nNasi Goreng,15000\n"))
            ->call('batal');

        $this->assertSame(0, Product::count());
    }

    /* ── Berkas sementara ────────────────────────────────────────────────── */

    #[Test]
    public function berkas_sementara_masuk_cakram_privat_bukan_folder_publik(): void
    {
        /*
         * Isinya daftar harga beli seluruh barang. Di folder yang disajikan web server, satu
         * tautan membocorkannya SELAMANYA tanpa cara mencabutnya — cacat yang sama persis
         * dengan foto bukti nota sebelum dipindahkan ke rute berpenjaga (komit 150248f).
         */
        $layar = $this->layar()->set('berkas', $this->berkas("nama,harga\nNasi Goreng,15000\n"));

        $jalur = $layar->get('jalurSementara');

        $this->assertNotNull($jalur);
        $this->assertStringStartsWith('impor-produk/', $jalur);
        Storage::disk('local')->assertExists($jalur);
    }

    #[Test]
    public function berkas_sementara_dibuang_sesudah_disimpan(): void
    {
        // Tidak ada satu pun layar yang memperlihatkan berkas ini masih ada, jadi tidak ada
        // yang akan membersihkannya. Menumpuknya membuat satu folder yang isinya makin lama
        // makin berharga bagi siapa pun yang menemukannya.
        $layar = $this->layar()->set('berkas', $this->berkas("nama,harga\nNasi Goreng,15000\n"));
        $jalur = $layar->get('jalurSementara');

        $layar->call('simpan');

        Storage::disk('local')->assertMissing($jalur);
    }

    #[Test]
    public function berkas_sementara_dibuang_juga_saat_dibatalkan(): void
    {
        // Jalur yang paling mudah terlupakan: orang membatalkan lebih sering daripada
        // menyimpan, jadi justru di sinilah berkasnya paling banyak menumpuk.
        $layar = $this->layar()->set('berkas', $this->berkas("nama,harga\nNasi Goreng,15000\n"));
        $jalur = $layar->get('jalurSementara');

        $layar->call('batal');

        Storage::disk('local')->assertMissing($jalur);
    }

    #[Test]
    public function berkas_yang_sudah_hilang_tidak_membuat_layarnya_meledak(): void
    {
        // Bisa terjadi sungguhan: dua tab, atau server yang membersihkan folder sementara.
        // Yang dibutuhkan pemilik adalah kalimat "unggah ulang", bukan halaman 500.
        $layar = $this->layar()->set('berkas', $this->berkas("nama,harga\nNasi Goreng,15000\n"));

        Storage::disk('local')->delete($layar->get('jalurSementara'));

        $layar->call('simpan')
            ->assertDispatched('toast', fn (string $n, array $d) => $d['jenis'] === 'galat');

        $this->assertSame(0, Product::count());
    }

    /* ── Berkas yang ditolak ─────────────────────────────────────────────── */

    #[Test]
    public function berkas_berekstensi_lain_ditolak(): void
    {
        $this->layar()
            ->set('berkas', $this->berkas('nama,harga', 'daftar.xlsx'))
            ->assertHasErrors('berkas');
    }

    /* ── Peran ───────────────────────────────────────────────────────────── */

    #[Test]
    public function kasir_tidak_boleh_membuka_layar_ini(): void
    {
        // Layar ini bisa mengubah harga SELURUH katalog dalam satu ketukan. Kasir yang bisa
        // membukanya bisa menurunkan harga seluruh barang sebelum shiftnya sendiri.
        $kasir = $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Kasir',
            'username' => 'kasir-impor',
            'password' => 'rahasia123',
            'outlet_id' => $this->outlet->getKey(),
        ]);

        $this->actingAs($kasir)
            ->get(route('owner.produk.impor'))
            ->assertRedirect(route('kasir.beranda'));
    }
}
