<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Setiap kotak unggah GAMBAR harus menawarkan kamera — di seluruh layar, bukan satu-satu.
 *
 * Cacat yang melahirkan penjaga ini sudah muncul DUA KALI di dua berkas berbeda, dan
 * pemiliknya menemukannya dengan mencoba sendiri di HP:
 *
 *   accept="image/jpeg,image/png,image/webp"
 *
 * Daftar jenis yang spesifik itu membuat banyak peramban Android TIDAK menawarkan kamera
 * sama sekali, karena aplikasi kameranya mendaftar sebagai penghasil `image/*` lalu
 * tersaring keluar oleh daftar itu. Yang tersisa cuma "pilih dari berkas" — dan untuk
 * pemilik warung yang memfoto struk atau memfoto barang di rak, itu berarti fiturnya
 * tidak bisa dipakai.
 *
 * Kenapa penjaganya memindai SUMBER dan bukan satu layar yang dirender: dua kali cacat
 * ini diperbaiki per layar, dan dua kali layar sebelahnya tertinggal — pertama di panel
 * rincian nota, lalu di formulir produk. Penjaga per-layar hanya menjaga layar yang sudah
 * pernah salah; yang menjaga layar BERIKUTNYA adalah yang memindai semuanya.
 *
 * `capture` juga dilarang, dan itu bukan kehati-hatian berlebihan: ia memaksa kamera TAPI
 * menghapus pilihan galeri di banyak peramban Android, sehingga foto yang sudah diambil
 * kemarin tidak bisa dipilih lagi dan orang dipaksa memfoto ulang struk yang sudah kusut.
 * Atribut itu terlihat seperti perbaikan, jadi ia akan ditambahkan orang lain kelak kalau
 * tidak ada yang menahannya.
 *
 * Yang TIDAK dijaga di sini, dengan sengaja: kotak unggah yang bukan gambar (impor Excel,
 * dan apa pun yang menyusul). Yang diperiksa hanya kotak yang `accept`-nya menyebut gambar
 * sama sekali — jadi penjaga ini tidak akan menuntut `image/*` pada kotak unggah CSV.
 */
class KotakUnggahGambarTest extends TestCase
{
    /**
     * Kotak yang HARUS ada minimal sebanyak ini.
     *
     * Angkanya bukan hiasan: tanpa batas bawah, hari ketika pemindainya berhenti menemukan
     * apa pun — selector berubah, Blade dipindah, atributnya ditulis lain — adalah hari ia
     * berhenti menjaga, dan ujinya tetap hijau. Kalau angka ini menghalangi karena kotaknya
     * memang berkurang, turunkan DENGAN alasan di komentar; jangan hapus asersinya.
     */
    private const MINIMAL_KOTAK = 3;

    public function test_setiap_kotak_unggah_gambar_menawarkan_kamera_tanpa_menghilangkan_galeri(): void
    {
        $kotak = $this->kotakUnggahGambar();

        $this->assertNotEmpty($kotak,
            'tidak ada satu pun kotak unggah gambar yang ditemukan — penjaga ini buta, bukan '
            .'layarnya yang bersih');

        $this->assertGreaterThanOrEqual(self::MINIMAL_KOTAK, count($kotak),
            'yang terbaca cuma '.count($kotak).' kotak unggah gambar, seharusnya minimal '
            .self::MINIMAL_KOTAK.' (foto bukti di formulir nota, foto bukti di panel rincian, '
            .'gambar produk). Kalau pemindainya berhenti menemukan sebagiannya, ia berhenti '
            .'menjaga tanpa memberi tahu siapa pun');

        foreach ($kotak as $k) {
            $this->assertStringContainsString('image/*', $k['accept'],
                "{$k['berkas']}:{$k['baris']} — accept wajib memuat image/*, kalau tidak banyak "
                .'peramban Android tidak menawarkan kamera sama sekali. accept sekarang: "'
                .$k['accept'].'"');

            $this->assertFalse($k['ada_capture'],
                "{$k['berkas']}:{$k['baris']} — jangan pasang capture: ia memaksa kamera TAPI "
                .'menghapus pilihan galeri, sehingga foto yang sudah diambil kemarin tidak bisa '
                .'dipilih lagi');
        }
    }

    /**
     * Semua `<input type="file">` bergambar di seluruh Blade layar, beserta letaknya.
     *
     * Diurai DOMDocument, BUKAN regex. Penjaga pemindai di proyek ini sudah tiga kali
     * berbohong, dan salah satunya justru karena `<input[^>]*>`: tanda `>` di dalam atribut
     * Alpine (mis. fungsi panah `=>`) memutus `[^>]*`, jadi pemindainya melewatkan elemen
     * yang ada dan melaporkan "tidak ditemukan" untuk kotak yang sudah benar.
     *
     * Nomor barisnya ikut dicari supaya pesan galatnya bisa langsung dibuka — pesan yang
     * cuma menyebut nama berkas menyuruh orangnya mencari sendiri di 900 baris Blade.
     *
     * @return list<array{berkas: string, baris: int, accept: string, ada_capture: bool}>
     */
    private function kotakUnggahGambar(): array
    {
        $hasil = [];

        foreach ($this->berkasBlade() as $berkas) {
            $isi = (string) file_get_contents($berkas);

            if (! str_contains($isi, 'type="file"')) {
                continue;
            }

            $dokumen = new \DOMDocument;

            // Blade bukan HTML sah (direktif, `{{ }}`, atribut Alpine), jadi libxml pasti
            // mengeluh. Keluhannya tidak relevan: yang dibaca cuma atribut, dan pohonnya
            // tetap tersusun. Dibungkam LOKAL supaya galat libxml di uji lain tetap terlihat.
            $sebelumnya = libxml_use_internal_errors(true);
            $dokumen->loadHTML('<?xml encoding="UTF-8"><div>'.$isi.'</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            libxml_use_internal_errors($sebelumnya);

            foreach ((new \DOMXPath($dokumen))->query('//input[@type="file"]') as $elemen) {
                /** @var \DOMElement $elemen */
                $accept = $elemen->getAttribute('accept');

                // Hanya kotak GAMBAR. Kotak unggah lain (impor Excel/CSV kelak) tidak boleh
                // ikut dituntut `image/*` — penjaga yang menuntut hal yang tidak masuk akal
                // akan dilonggarkan seluruhnya oleh orang berikutnya.
                if (! str_contains($accept, 'image')) {
                    continue;
                }

                $hasil[] = [
                    'berkas' => str_replace(base_path().'/', '', $berkas),
                    'baris' => $elemen->getLineNo(),
                    'accept' => $accept,
                    // hasAttribute, bukan nilainya: `capture` boleh berdiri tanpa nilai.
                    'ada_capture' => $elemen->hasAttribute('capture'),
                ];
            }
        }

        return $hasil;
    }

    /** @return list<string> */
    private function berkasBlade(): array
    {
        $hasil = [];

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views/livewire')),
        ) as $berkas) {
            if ($berkas->isFile() && str_ends_with($berkas->getFilename(), '.blade.php')) {
                $hasil[] = $berkas->getPathname();
            }
        }

        return $hasil;
    }
}
