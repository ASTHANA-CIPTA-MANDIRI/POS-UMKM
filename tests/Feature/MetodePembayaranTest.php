<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use Tests\TestCase;

/**
 * Menjaga kesesuaian antara tombol metode pembayaran di layar kasir dan enum yang
 * memvalidasinya di server.
 *
 * Keduanya hidup di bahasa yang berbeda, jadi tidak ada yang menghubungkannya selain
 * pengujian ini. Ketidaksesuaiannya pernah terjadi: layar menawarkan "Transfer"
 * sementara enum tidak mengenalnya, sehingga transaksi yang dibayar dengan cara itu
 * ditolak 422 dan tertahan di antrean tanpa kasir tahu sebabnya.
 */
class MetodePembayaranTest extends TestCase
{
    public function test_metode_di_layar_kasir_dikenal_server(): void
    {
        $kode = $this->kodeDiKlien();

        $this->assertNotEmpty($kode, 'Daftar metodeTersedia tidak terbaca di kasir.js.');

        foreach ($kode as $satu) {
            $this->assertNotNull(
                PaymentMethod::tryFrom($satu),
                "Metode '{$satu}' ditawarkan di layar kasir tapi tidak ada di enum PaymentMethod.",
            );
        }
    }

    public function test_tunai_dan_kasbon_selalu_tersedia(): void
    {
        $kode = $this->kodeDiKlien();

        // Tunai adalah satu-satunya yang menyentuh laci kas, dan kasbon satu-satunya
        // yang membuat piutang. Warung tanpa keduanya bukan warung.
        $this->assertContains(PaymentMethod::Cash->value, $kode);
        $this->assertContains(PaymentMethod::Kasbon->value, $kode);
    }

    /** @return array<int, string> */
    private function kodeDiKlien(): array
    {
        $isi = file_get_contents(base_path('resources/js/kasir.js'));

        if (! preg_match('/metodeTersedia:\s*\[(.*?)\]/s', $isi, $blok)) {
            return [];
        }

        preg_match_all("/kode:\s*'([^']+)'/", $blok[1], $cocok);

        return $cocok[1];
    }
}
