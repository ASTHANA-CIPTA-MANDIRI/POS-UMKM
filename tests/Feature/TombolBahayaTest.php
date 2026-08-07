<?php

namespace Tests\Feature;

use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Produk\Produk as LayarProduk;
use App\Models\Produk\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
        $tombol = $this->tombolHapus($this->halamanProduk());

        $this->assertNotEmpty($tombol, 'tidak ada tombol hapus yang ditemukan');

        // Layar ini merender DUA pemicu hapus per barang — satu untuk kartu di HP, satu untuk
        // tabel di dekstop — jadi jumlahnya ditegaskan. Kalau salah satu hilang dari hasil
        // pindaian, penjaganya berhenti memeriksa separuh layar dan tetap hijau.
        $this->assertGreaterThanOrEqual(2, count($tombol),
            'kartu HP dan tabel dekstop masing-masing punya pemicu hapus; yang terbaca cuma '
            .count($tombol));

        foreach ($tombol as $nomor => ['label' => $label, 'kelas' => $kelas]) {
            $this->assertStringNotContainsString('text-umber-soft', $kelas,
                "pemicu hapus ke-{$nomor} (\"{$label}\") tidak boleh kelabu di keadaan istirahat");
        }
    }

    /**
     * Tombol hapus beserta kelasnya, dibaca dengan pengurai HTML dan BUKAN regex.
     *
     * Bentuk lamanya `preg_match_all('/<button[^>]*aria-label="Hapus[^>]*>/')`, dan itu
     * sudah benar-benar gagal: sejak pemicu hapus memanggil dialog konfirmasi, atributnya
     * memuat fungsi panah `.then((ya) => ya && $wire.hapus(...))`. Tanda `>` di dalam `=>`
     * memutus `[^>]*`, jadi regexnya tidak pernah sampai ke `aria-label` — dan penjaganya
     * melaporkan "tidak ada tombol hapus yang ditemukan" untuk tombol yang ada dan sudah
     * benar merah.
     *
     * Yang menyelamatkan keadaan itu bukan regexnya, melainkan `assertNotEmpty` di
     * pemanggilnya: tanpa baris itu, `foreach` atas larik kosong membuat ujinya HIJAU tanpa
     * memeriksa apa pun. Setiap penjaga yang memindai harus menegaskan bahwa ia menemukan
     * sesuatu — kalau tidak, hari ketika ia berhenti menemukan apa pun adalah hari ia
     * berhenti menjaga, dan tidak ada yang tahu.
     *
     * Elemennya tidak dibatasi <button>: <x-aksi> boleh merender <a> maupun <button>, dan
     * penjaga yang mensyaratkan tag tertentu akan buta begitu komponennya berubah.
     *
     * DAFTAR, bukan peta berkunci label. Versi pertama memakai aria-label sebagai kunci, dan
     * uji mutasi langsung membuktikannya buta: layar ini merender dua pemicu hapus dengan
     * label yang SAMA — satu di kartu HP, satu di tabel dekstop — jadi yang satu menimpa yang
     * lain dan hanya separuh layar yang pernah diperiksa. Tombol yang dibuat kelabu sengaja
     * di kartu HP lolos hijau.
     *
     * @return list<array{label: string, kelas: string}>
     */
    private function tombolHapus(string $html): array
    {
        $dokumen = new \DOMDocument;

        // Atribut Alpine memuat `&&` yang bukan entitas HTML sah, jadi libxml mengeluh.
        // Keluhannya tidak relevan di sini: yang dibaca cuma atribut, dan pohonnya tetap
        // tersusun. Dibungkam LOKAL supaya galat libxml di uji lain tetap terlihat.
        $sebelumnya = libxml_use_internal_errors(true);
        $dokumen->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($sebelumnya);

        $hasil = [];

        foreach ((new \DOMXPath($dokumen))->query('//*[starts-with(@aria-label, "Hapus")]') as $elemen) {
            /** @var \DOMElement $elemen */
            $hasil[] = [
                'label' => $elemen->getAttribute('aria-label'),
                'kelas' => $elemen->getAttribute('class'),
            ];
        }

        return $hasil;
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

    /**
     * Merahnya harus ADA di keadaan istirahat — bukan hanya di balik `hover:`.
     *
     * Penjaga di atas hanya melarang merah PEKAT yang diketik sendiri, jadi ia TIDAK
     * menangkap cacat yang sesungguhnya terjadi: tombol "Batalkan nota" di layar Pembelian
     * kelabu (`text-umber` + `border-line`) dan merahnya hanya muncul lewat
     * `hover:text-merah-tua`. Di tablet dan HP — tempat layar owner benar-benar dipakai —
     * hover TIDAK ADA, jadi tanda bahayanya tidak pernah muncul sama sekali. Cacat yang
     * identik sudah pernah diperbaiki untuk tombol ikon hapus; ia kembali di layar
     * berikutnya justru karena tidak ada uji yang menjaganya.
     *
     * Yang diperiksa: setiap `<button>`/`<a>` yang labelnya menyebut tindakan merusak wajib
     * punya penanda merah TANPA awalan varian (`hover:`, `focus:`, `active:`, `group-hover:`)
     * — entah kelas bersama `.tombol-bahaya`, entah tint `bg-merah/10` + `text-merah-tua`.
     */
    public function test_pemicu_tindakan_merusak_tidak_mengandalkan_hover_untuk_merahnya(): void
    {
        $pelanggar = [];

        foreach ((new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        )) as $berkas) {
            if (! $berkas->isFile() || ! str_ends_with($berkas->getFilename(), '.blade.php')) {
                continue;
            }

            $isi = (string) file_get_contents($berkas->getPathname());
            $jalur = str_replace(base_path().'/', '', $berkas->getPathname());

            /*
             * Ambil elemennya UTUH (tag + isinya): labelnya yang menentukan apakah tombol ini
             * memang tindakan merusak, dan label itu ada di dalam tag, bukan di atributnya.
             *
             * Atributnya TIDAK boleh dicocokkan dengan `[^>]*`: `wire:click="hapus('{{ $p->id }}')"`
             * mengandung `>` dari panah objek Blade, jadi tagnya terpotong di tengah dan
             * potongan sisanya terbaca sebagai label. Versi pertama saya begitu, dan hasilnya
             * empat tuduhan palsu — termasuk tombol `.tombol-bahaya` yang justru sudah benar.
             * Karena itu nilai atribut ber-kutip dilewati utuh lebih dulu.
             */
            preg_match_all('/<(button|a)\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>(.*?)<\/\1>/s', $isi, $cocok, PREG_SET_ORDER);

            foreach ($cocok as $satu) {
                [, , $atribut, $dalam] = $satu;

                // Komentar Blade di dalam tombol bukan label; buang dulu supaya penjelasan
                // "kenapa TIDAK merah" tidak ikut membuat tombolnya dituduh merusak.
                $dalam = preg_replace('/\{\{--.*?--\}\}/s', ' ', $dalam) ?? '';
                $label = trim(preg_replace('/\s+/', ' ', strip_tags($dalam)) ?? '');

                if (preg_match('/aria-label="([^"]*)"/', $atribut, $labelAria) === 1) {
                    $label .= ' '.$labelAria[1];
                }

                // Kata kerja merusak, dalam bahasa layar ini. "Tandai barang sudah datang"
                // sengaja TIDAK termasuk: aksinya idempoten dan tidak ada yang hilang.
                if (preg_match('/\b(hapus|batalkan|buang|void)\b/i', $label) !== 1) {
                    continue;
                }

                // Tombol yang MEMBATALKAN pertanyaannya ("Tidak jadi", "Belum") bukan pemicu.
                if (preg_match('/\b(tidak jadi|batal saja|belum)\b/i', $label) === 1) {
                    continue;
                }

                // Kumpulkan semua kelas: atribut `class` statis maupun `:class`/`x-bind:class`.
                preg_match_all('/(?::|x-bind:)?class="([^"]*)"/', $atribut, $kelasSemua);
                $kelas = implode(' ', $kelasSemua[1] ?? []);

                // Buang token yang butuh interaksi lebih dulu. Sisanya = keadaan ISTIRAHAT,
                // satu-satunya keadaan yang dilihat pemakai layar sentuh.
                $istirahat = implode(' ', array_filter(
                    preg_split('/\s+/', $kelas) ?: [],
                    static fn (string $token): bool => preg_match('/\b(hover|focus|focus-visible|active|group-hover):/', $token) !== 1,
                ));

                if (preg_match('/\b(tombol-bahaya|text-merah|bg-merah)/', $istirahat) === 1) {
                    continue;
                }

                $pelanggar[] = $jalur.' — "'.Str::limit($label, 40).'"';
            }
        }

        $this->assertSame([], $pelanggar,
            'pemicu tindakan merusak wajib merah SEJAK ISTIRAHAT (tint bg-merah/10 + '
            .'text-merah-tua, atau .tombol-bahaya) — di HP tidak ada hover: '
            .implode(' | ', $pelanggar));
    }
}
