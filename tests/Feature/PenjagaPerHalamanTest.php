<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Menjaga agar SEMUA daftar memakai satu setelan jumlah baris per halaman.
 *
 * Bukan soal selera. Daftar yang panjangnya berbeda-beda membuat orang kehilangan
 * pegangan: ia menghitung "tinggal satu halaman lagi" dari kebiasaan di layar
 * sebelumnya, lalu keliru di layar berikutnya. Dan angka yang diketik ulang di tiap
 * komponen berarti mengubahnya nanti harus menyisir seluruh repo — satu yang terlewat
 * dan ketidakkonsistenannya kembali tanpa ada yang sadar.
 *
 * Uji ini memeriksa SUMBERNYA, bukan perilakunya, karena itulah satu-satunya cara
 * menahan komponen yang BELUM ADA. Fitur berikutnya akan gagal di sini kalau menuliskan
 * angkanya sendiri.
 */
class PenjagaPerHalamanTest extends TestCase
{
    public function test_setelan_per_halaman_terpusat_dan_bernilai_sepuluh(): void
    {
        $this->assertSame(10, (int) config('nampan.per_halaman'));
    }

    public function test_tidak_ada_komponen_yang_menuliskan_jumlah_halamannya_sendiri(): void
    {
        $pelanggar = [];

        foreach ($this->berkasPhp(app_path()) as $berkas) {
            $isi = (string) file_get_contents($berkas);

            // paginate(15) / simplePaginate(25) / perHalaman = 25 — angka apa pun yang
            // diketik langsung. paginate(config(...)) dan paginate($perHalaman) lolos.
            if (preg_match('/(?:simplePaginate|paginate)\(\s*\d+/', $isi) === 1
                || preg_match('/\$perHalaman\s*=\s*[1-9]\d*/', $isi) === 1) {
                $pelanggar[] = str_replace(base_path().'/', '', $berkas);
            }
        }

        $this->assertSame([], $pelanggar,
            "Pakai config('nampan.per_halaman'), jangan angka langsung: ".implode(', ', $pelanggar));
    }

    /** @return array<int, string> */
    private function berkasPhp(string $dir): array
    {
        $hasil = [];
        $isi = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($isi as $berkas) {
            if ($berkas->isFile() && $berkas->getExtension() === 'php') {
                $hasil[] = $berkas->getPathname();
            }
        }

        return $hasil;
    }
}
