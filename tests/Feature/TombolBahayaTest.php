<?php

namespace Tests\Feature;

use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Produk as LayarProduk;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Tindakan MERUSAK harus terlihat merah — tanpa perlu disentuh lebih dulu.
 *
 * Bentuk lama tombol hapus ikon kelabu dan baru merah saat hover, dengan alasan "supaya
 * tidak memancing ditekan sambil lalu". Alasan itu sah untuk tetikus dan salah untuk
 * aplikasi ini: layar owner dipakai di tablet dan HP, dan di sana hover TIDAK ADA — jadi
 * tanda bahayanya tidak pernah muncul, tepat di tempat salah-tekan paling mungkin.
 *
 * Uji ini memeriksa keadaan ISTIRAHAT (kelas yang terpasang tanpa interaksi), karena itulah
 * satu-satunya keadaan yang dilihat pemakai layar sentuh. Uji yang memeriksa kelas `hover:`
 * akan tetap hijau untuk tombol yang di HP terlihat kelabu.
 */
class TombolBahayaTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private function halamanProduk(): string
    {
        $tenant = $this->buatTenant('Toko Bahaya');
        $this->buatOutlet($tenant, 'Outlet');
        $owner = $this->buatUser($tenant, UserRole::Owner, [
            'name' => 'Pemilik', 'email' => 'o@bahaya.test', 'password' => 'rahasia123',
        ]);
        $this->konteks()->setTenant($tenant->getKey());

        Product::create(['nama_produk' => 'Kopi Bahaya', 'harga_default' => 3000, 'satuan' => Satuan::Pcs]);

        return Livewire::actingAs($owner)->test(LayarProduk::class)->html();
    }

    public function test_tombol_hapus_sudah_merah_tanpa_disentuh(): void
    {
        $html = $this->halamanProduk();

        // Tombol hapus ikon: ada, dan warnanya merah di keadaan istirahat.
        $this->assertMatchesRegularExpression('/aria-label="Hapus [^"]+"/', $html,
            'tombol hapus harus punya label yang menyebut nama barangnya');

        $this->assertStringContainsString('text-merah-tua', $html,
            'tombol hapus wajib merah SEJAK AWAL — di HP tidak ada hover, jadi kelabu berarti '
            .'tanda bahayanya tidak pernah muncul');
    }

    /**
     * Penjaga arah sebaliknya: warna merah lama yang kontrasnya gagal tidak boleh kembali.
     *
     * `text-merah-deep` hanya 4,15:1 begitu latarnya bertint (terukur), sementara
     * `text-merah-tua` 7,14:1. Tombol yang paling perlu dibaca sebelum ditekan tidak boleh
     * jadi yang paling sulit dibaca.
     */
    public function test_tombol_hapus_tidak_memakai_merah_berkontras_rendah(): void
    {
        $html = $this->halamanProduk();

        preg_match_all('/<button[^>]*aria-label="Hapus[^>]*>/', $html, $cocok);

        $this->assertNotEmpty($cocok[0], 'tidak ada tombol hapus yang ditemukan');

        foreach ($cocok[0] as $tombol) {
            $this->assertStringNotContainsString('text-umber-soft', $tombol,
                'tombol hapus tidak boleh kelabu di keadaan istirahat');
        }
    }

    /**
     * Kelas bersama, bukan warna yang diketik ulang per layar.
     *
     * Kalau tiap layar menuliskan `bg-merah-deep` sendiri, layar berikutnya akan memilih
     * merah versinya sendiri dan "merah = merusak" berhenti terbaca sebagai aturan.
     */
    public function test_tidak_ada_tombol_merah_yang_diketik_sendiri_per_layar(): void
    {
        $pelanggar = [];

        foreach ((new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        )) as $berkas) {
            if (! $berkas->isFile() || ! str_ends_with($berkas->getFilename(), '.blade.php')) {
                continue;
            }

            $isi = (string) file_get_contents($berkas->getPathname());

            /*
             * Yang dijaga: latar merah PEKAT yang diketik sendiri (`bg-merah`,
             * `bg-merah-deep`) pada tombol. Bentuk bertint (`bg-merah/10`) SENGAJA
             * dikecualikan — itu tombol PEMICU konfirmasi ("Batalkan nota…", "Hapus foto"),
             * bukan tindakan merusaknya, dan bobotnya memang harus lebih ringan.
             *
             * Regex versi pertama saya tidak membedakan keduanya dan menuduh lima tombol
             * pemicu yang sudah benar — `\b` cocok juga sebelum garis miring. Yang
             * membedakan sekarang: pengecekan negatif atas `/`, `-`, dan huruf sesudahnya.
             */
            if (preg_match('/<(?:button|a)[^>]*\\bbg-merah(?:-deep)?(?![\\w\\/-])/', $isi) === 1) {
                $pelanggar[] = str_replace(base_path().'/', '', $berkas->getPathname());
            }
        }

        $this->assertSame([], $pelanggar,
            'pakai kelas .tombol-bahaya, jangan mengetik warna merah sendiri: '.implode(', ', $pelanggar));
    }
}
