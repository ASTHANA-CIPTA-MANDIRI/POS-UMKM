<?php

namespace Tests\Feature;

use App\Support\NomorHp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Penyeragam nomor telepon.
 *
 * KENAPA BERKAS INI ADA. Nomor HP adalah satu-satunya penanda orang yang benar-benar dipakai
 * di warung — nama "Budi" ada tiga di buku kasbon mana pun. Kalau "0812-3456-7890",
 * "+62 812 3456 7890", dan "081234567890" tersimpan sebagai tiga teks yang berbeda, ketiganya
 * lolos aturan `unique` dan utang SATU orang terpecah ke tiga baris. Pemilik membuka salah
 * satunya, melihat Rp 50.000, dan menagih segitu — padahal yang benar Rp 150.000. Tidak ada
 * galat di layar mana pun; selisihnya muncul sebagai uang yang tidak pernah kembali.
 *
 * Yang dijaga paling keras, dan dua-duanya cacat yang SUNYI:
 *
 *  1. Tiga cara menulis satu nomor menghasilkan satu teks yang SAMA PERSIS. Kalau tidak,
 *     gerbang nomor unik di layar Pelanggan tidak menahan apa pun.
 *  2. Awalan 62 TIDAK dipotong sembarangan. Nomor yang berubah sendiri lebih buruk daripada
 *     nomor yang tidak dirapikan — yang satu bisa diperbaiki pemilik, yang lain tidak pernah
 *     ia sadari.
 */
class NomorHpTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function bentukSatuOrang(): array
    {
        return [
            'polos' => ['081234567890', '081234567890'],
            'tanda hubung' => ['0812-3456-7890', '081234567890'],
            'spasi' => ['0812 3456 7890', '081234567890'],
            'titik' => ['0812.3456.7890', '081234567890'],
            'kode negara plus' => ['+6281234567890', '081234567890'],
            'kode negara plus berspasi' => ['+62 812-3456-7890', '081234567890'],
            'kode negara tanpa plus' => ['6281234567890', '081234567890'],
            'kurung kode negara' => ['(+62) 812 3456 7890', '081234567890'],
        ];
    }

    #[Test]
    #[DataProvider('bentukSatuOrang')]
    public function delapan_cara_menulis_satu_nomor_menjadi_teks_yang_sama(string $diketik, string $harapan): void
    {
        $this->assertSame($harapan, NomorHp::bakukan($diketik));
    }

    #[Test]
    public function awalan_62_hanya_dipotong_kalau_sisanya_nomor_hp(): void
    {
        /*
         * Ini pagarnya, dan tanpanya penyeragaman jadi perusak.
         *
         * Semua nomor HP Indonesia dimulai 8 sesudah kode negaranya, jadi "62812…" pasti kode
         * negara. Nomor lain yang kebetulan berawalan 62 — ekstensi internal, nomor mesin —
         * BUKAN kode negara, dan memotongnya mengubah nomor orang menjadi nomor orang lain.
         */
        $this->assertSame('081234567890', NomorHp::bakukan('6281234567890'), 'kontrol: 62 + 8 memang kode negara');
        $this->assertSame('621234567', NomorHp::bakukan('621234567'), '62 yang tidak diikuti 8 dibiarkan utuh');
    }

    #[Test]
    public function nomor_rumah_berawalan_nol_tidak_disentuh(): void
    {
        // 0215551234 (Jakarta) bukan nomor HP dan tidak boleh diubah bentuknya.
        $this->assertSame('0215551234', NomorHp::bakukan('(021) 555-1234'));
    }

    #[Test]
    public function nomor_asing_kehilangan_plusnya_tapi_angkanya_utuh(): void
    {
        // Angkanya harus tetap dikenali pemilik yang menuliskannya; yang dibuang cuma tandanya
        // supaya perbandingan keunikan bekerja atas satu bentuk saja.
        $this->assertSame('6591234567', NomorHp::bakukan('+65 9123 4567'));
    }

    #[Test]
    public function masukan_kosong_dan_tanpa_angka_menjadi_null_bukan_teks_kosong(): void
    {
        /*
         * Bedanya menentukan apa yang masuk basis data. Teks kosong yang tersimpan di kolom
         * `no_hp` membuat DUA pelanggan tanpa nomor saling bertabrakan di gerbang keunikan —
         * pelanggan kedua yang nomornya memang tidak diketahui akan ditolak dengan pesan
         * "nomor ini sudah dipakai Budi", untuk nomor yang tidak pernah ia isi.
         */
        $this->assertNull(NomorHp::bakukan(null));
        $this->assertNull(NomorHp::bakukan(''));
        $this->assertNull(NomorHp::bakukan('   '));
        $this->assertNull(NomorHp::bakukan('tidak punya'));
        $this->assertNull(NomorHp::bakukan('-'));
    }

    #[Test]
    public function panjang_yang_tidak_masuk_akal_dinyatakan_tidak_sah(): void
    {
        $this->assertFalse(NomorHp::sah('0812'), 'empat digit hampir pasti salah pencet');
        $this->assertFalse(NomorHp::sah('08123456789012345678'), 'melewati batas E.164');

        $this->assertTrue(NomorHp::sah('0215551'), 'tujuh digit: nomor rumah terpendek masih sah');
        $this->assertTrue(NomorHp::sah('081234567890'));
    }

    #[Test]
    public function kosong_dianggap_sah_karena_kolomnya_memang_opsional(): void
    {
        // "Wajib diisi atau tidak" adalah keputusan pemanggilnya. Kalau sah() menolak kosong,
        // setiap layar yang memakainya harus mendahuluinya dengan pemeriksaan sendiri — dan
        // satu layar pasti lupa, lalu menolak pelanggan yang nomornya memang tidak diketahui.
        $this->assertTrue(NomorHp::sah(null));
        $this->assertTrue(NomorHp::sah(''));
    }

    #[Test]
    public function bentuk_whatsapp_memakai_62_tanpa_plus(): void
    {
        // wa.me menolak tanda plus, dan tautan yang salah bentuk membuka WhatsApp ke
        // percakapan kosong — kelihatan bekerja, tapi pesannya tidak pernah sampai.
        $this->assertSame('6281234567890', NomorHp::untukWhatsapp('0812-3456-7890'));
        $this->assertSame('6281234567890', NomorHp::untukWhatsapp('+62 812 3456 7890'));
    }

    #[Test]
    public function nomor_asing_tidak_dipaksa_berawalan_62(): void
    {
        // Menempelkan 62 di depan nomor Singapura membuat tautannya menunjuk orang yang tidak
        // ada — atau lebih buruk, orang lain yang nomornya kebetulan cocok.
        $this->assertSame('6591234567', NomorHp::untukWhatsapp('+65 9123 4567'));
        $this->assertNull(NomorHp::untukWhatsapp(null));
    }
}
