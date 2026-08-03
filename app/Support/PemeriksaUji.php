<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Menjalankan kedua suite lalu membaca hasilnya dari JUnit XML.
 *
 * XML, bukan keluaran teksnya: format tampilan PHPUnit dan node berubah antar-versi,
 * sedangkan JUnit tidak. Laporan yang salah membaca angkanya lebih berbahaya daripada
 * laporan tanpa angka — ia membuat orang mengabaikan angka, lalu kegagalan sungguhan
 * ikut terabaikan.
 */
class PemeriksaUji
{
    /**
     * @return array{php: array{jumlah: int, gagal: int, dilewati: int, nama: array<int, string>}, js: array{jumlah: int, gagal: int, dilewati: int, nama: array<int, string>}}
     */
    public function jalankan(): array
    {
        $berkasPhp = storage_path('app/laporan-php.xml');
        $berkasJs = storage_path('app/laporan-js.xml');

        /*
         * Lingkungan uji DIPAKSA untuk anak proses.
         *
         * Proses ini sudah memuat .env, jadi DB_CONNECTION=mysql ada di lingkungannya dan
         * ikut terwaris oleh `php artisan test`. Pernah terjadi: RefreshDatabase
         * menjalankan migrate:fresh pada database pengembangan dan menghapus semuanya.
         * force="true" di phpunit.xml TIDAK cukup — variabel yang benar-benar ada di
         * lingkungan OS tetap menang.
         */
        $lingkunganUji = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'SESSION_DRIVER' => 'array',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'null',
        ];

        // Bentuk LARIK, bukan untai perintah: jalur proyek bisa mengandung spasi
        // ("MY WORK") dan untai yang lewat shell akan terpotong di situ.
        Process::path(base_path())->env($lingkunganUji)->timeout(600)
            ->run(['php', 'artisan', 'test', "--log-junit={$berkasPhp}"]);

        // Glob diperluas di PHP karena tanpa shell tidak ada yang memperluasnya.
        $berkasUji = glob(base_path('tests/js/*.test.mjs')) ?: [];

        /*
         * Sengaja TIDAK memeriksa node_modules.
         *
         * Uji JS di proyek ini tidak mengimpor satu pun paket luar — Alpine dan Telegram
         * dipalsukan di dalam ujinya sendiri. Terbukti: suite JS tetap 141/149 di salinan
         * bersih tanpa node_modules. Menuntut node_modules justru akan menyembunyikan
         * hasil yang sah setiap kali `npm ci` gagal di CI.
         */
        if ($berkasUji !== []) {
            Process::path(base_path())->timeout(600)->run(array_merge(
                ['node', '--test', '--test-reporter=junit', "--test-reporter-destination={$berkasJs}"],
                $berkasUji,
            ));
        }

        $hasil = ['php' => $this->bacaJunit($berkasPhp), 'js' => $this->bacaJunit($berkasJs)];

        foreach ([$berkasPhp, $berkasJs] as $berkas) {
            if (is_file($berkas)) {
                unlink($berkas);
            }
        }

        return $hasil;
    }

    /** @return array{jumlah: int, gagal: int, dilewati: int, nama: array<int, string>} */
    private function bacaJunit(string $berkas): array
    {
        $kosong = ['jumlah' => 0, 'gagal' => 0, 'dilewati' => 0, 'nama' => []];

        if (! is_file($berkas)) {
            return $kosong;
        }

        $xml = @simplexml_load_string((string) file_get_contents($berkas));

        if ($xml === false) {
            return $kosong;
        }

        $kasus = $xml->xpath('//testcase') ?: [];
        $gagal = [];
        $dilewati = 0;

        foreach ($kasus as $satu) {
            /*
             * isset(), BUKAN !== null.
             *
             * SimpleXML mengembalikan objek KOSONG untuk anak yang tidak ada, jadi
             * "$satu->failure !== null" selalu benar dan seluruh uji terbaca gagal.
             */
            if (isset($satu->failure) || isset($satu->error)) {
                // Nama uji di proyek ini menerangkan perilakunya, jadi daftar nama uji
                // yang gagal sudah menjadi daftar cacat.
                $gagal[] = $this->manusiawi((string) ($satu['name'] ?? '?'));
            } elseif (isset($satu->skipped)) {
                $dilewati++;
            }
        }

        return ['jumlah' => count($kasus), 'gagal' => count($gagal), 'dilewati' => $dilewati, 'nama' => $gagal];
    }

    private function manusiawi(string $nama): string
    {
        return Str::of($nama)->after('::')->replaceStart('test_', '')->replace('_', ' ')->trim()->toString();
    }
}
