<?php

namespace Tests\Feature;

use App\Actions\Produk\HitungHppAction;
use App\Actions\Produk\SaranHargaAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Saran harga jual dari HPP dan target margin.
 *
 * Tiga hal yang dijaga, dan ketiganya berujung pada margin yang meleset TANPA SUARA:
 *
 *  1. Rumusnya HPP / (1 - margin), BUKAN HPP x (1 + margin). Yang kedua menghasilkan MARKUP:
 *     modal 10.000 dengan "30%" jadi 13.000, dan marginnya cuma 23%. Pemilik yang
 *     menargetkan 30% mendapat 23% tanpa pernah tahu.
 *  2. Pembulatan selalu KE ATAS. Ke bawah memakan margin pada SETIAP barang sekaligus.
 *  3. Yang ditampilkan margin NYATA sesudah pembulatan, bukan targetnya. Pemilik yang
 *     menghitung sendiri akan menemukan selisihnya, dan sesudah itu tidak percaya lagi.
 */
class SaranHargaTest extends TestCase
{
    private function aksi(): SaranHargaAction
    {
        return app(SaranHargaAction::class);
    }

    /* ── Rumus ───────────────────────────────────────────────────────────── */

    #[Test]
    public function rumusnya_margin_bukan_markup(): void
    {
        /*
         * Modal 10.000, target 30%:
         *   BENAR  10.000 / (1 - 0,30) = 14.286  → margin (14.286-10.000)/14.286 = 30%
         *   SALAH  10.000 x 1,30       = 13.000  → margin (13.000-10.000)/13.000 = 23%
         */
        $this->assertSame(14285.71, $this->aksi()->untuk(10000, 30)['harga']);
    }

    #[Test]
    public function harga_saran_benar_benar_menghasilkan_margin_yang_ditargetkan(): void
    {
        /*
         * Uji yang mengikat dua berkas sekaligus: kalau SaranHargaAction dan
         * HitungHppAction::margin() memakai pembagi yang berbeda, angka yang ditampilkan
         * sesudah harga ini dipakai TIDAK akan cocok dengan targetnya — dan pemilik melihat
         * "target 30%" di satu layar dan "23%" di layar lain untuk barang yang sama.
         */
        $saran = $this->aksi()->untuk(10000, 30);
        $margin = app(HitungHppAction::class)->margin(10000, $saran['harga']);

        $this->assertSame(30.0, $margin['persen']);
    }

    /* ── Pembulatan ──────────────────────────────────────────────────────── */

    #[Test]
    public function pembulatan_selalu_ke_atas(): void
    {
        // Ke bawah memakan margin diam-diam, dan bukan pada satu barang — pada SETIAP barang
        // yang harganya disusun dari saran ini.
        $hasil = $this->aksi()->untuk(10000, 30);

        $this->assertSame(14500.0, $hasil['hargaBulat'], '14.285,71 dibulatkan ke atas ke pecahan 500');
        $this->assertGreaterThan($hasil['harga'], $hasil['hargaBulat']);
    }

    #[Test]
    public function pecahan_pembulatan_ikut_besarnya_harga(): void
    {
        /*
         * Satu pecahan tetap salah di dua ujung: membulatkan ke Rp 500 pada barang Rp 2.140
         * menaikkannya 17%, sementara membulatkan ke Rp 100 pada barang Rp 72.350
         * menghasilkan Rp 72.400 — angka yang tidak pernah ditulis di daftar harga mana pun.
         */
        $this->assertSame(2200.0, $this->aksi()->untuk(1500, 30)['hargaBulat'], 'di bawah 5.000 → pecahan 100');
        $this->assertSame(14500.0, $this->aksi()->untuk(10000, 30)['hargaBulat'], 'di bawah 50.000 → pecahan 500');
        $this->assertSame(72000.0, $this->aksi()->untuk(50000, 30)['hargaBulat'], 'di atas 50.000 → pecahan 1.000');
    }

    #[Test]
    public function harga_yang_sudah_pas_di_pecahan_tidak_dinaikkan_lagi(): void
    {
        // Pagar untuk pembulatan ke atas: ceil() yang salah tanda akan menambahkan satu
        // pecahan penuh pada angka yang sudah bulat, dan tiap penyimpanan ulang menaikkannya
        // lagi tanpa henti.
        $this->assertSame(10000.0, $this->aksi()->untuk(5000, 50)['hargaBulat']);
    }

    #[Test]
    public function yang_ditampilkan_margin_nyata_sesudah_pembulatan_bukan_targetnya(): void
    {
        /*
         * Pembulatan selalu menggeser marginnya sedikit. Pemilik yang menargetkan 30% lalu
         * melihat "30%" padahal nyatanya 31% akan menyimpulkan aplikasinya menyembunyikan
         * sesuatu begitu ia menghitung sendiri.
         */
        $hasil = $this->aksi()->untuk(10000, 30);

        // (14.500 - 10.000) / 14.500 = 31,03%
        $this->assertSame(31.0, $hasil['marginNyata']);
        $this->assertNotSame(30.0, $hasil['marginNyata'], 'angka yang jujur lebih berguna daripada angka yang rapi');
    }

    /* ── Penolakan ───────────────────────────────────────────────────────── */

    #[Test]
    public function hpp_yang_belum_diketahui_tidak_menghasilkan_saran(): void
    {
        // Saran harga yang dibangun dari modal yang tidak diketahui adalah tebakan yang
        // berpakaian angka — dan pemilik akan memakainya karena bentuknya meyakinkan.
        $hasil = $this->aksi()->untuk(null, 30);

        $this->assertNull($hasil['harga']);
        $this->assertNull($hasil['hargaBulat']);
    }

    #[Test]
    public function hpp_nol_tidak_menghasilkan_saran(): void
    {
        // Modal nol menghasilkan saran harga nol pada target berapa pun — angka yang sah
        // secara matematika dan tidak berarti apa-apa di daftar harga.
        $this->assertNull($this->aksi()->untuk(0, 30)['harga']);
    }

    #[Test]
    public function margin_seratus_persen_ke_atas_ditolak_bukan_menghasilkan_harga_minus(): void
    {
        /*
         * Pada 100% pembaginya NOL, dan di atas 100% hasilnya NEGATIF: aplikasi akan
         * menyarankan harga minus tanpa satu pun galat, dan angka itu terlihat seperti
         * hitungan yang sah.
         */
        $this->assertNull($this->aksi()->untuk(10000, 100)['harga']);
        $this->assertNull($this->aksi()->untuk(10000, 150)['harga']);
    }

    #[Test]
    public function margin_negatif_ditolak(): void
    {
        $this->assertNull($this->aksi()->untuk(10000, -10)['harga']);
    }

    #[Test]
    public function margin_nol_menghasilkan_harga_sama_dengan_modal(): void
    {
        // Bukan kasus tepi yang mengada-ada: barang pancingan memang kadang dijual seharga
        // modal. Yang dijaga: nol tidak ikut tertolak bersama nilai yang tidak masuk akal.
        $this->assertSame(10000.0, $this->aksi()->untuk(10000, 0)['harga']);
    }
}
