<?php

namespace App\Support;

/**
 * Penyeragam nomor telepon yang DIKETIK ORANG menjadi satu bentuk baku.
 *
 * KENAPA BERKAS INI ADA, dan kenapa ia bukan sekadar kerapian. Nomor HP adalah satu-satunya
 * penanda orang yang benar-benar dipakai di warung — nama "Budi" ada tiga di buku kasbon mana
 * pun. Tanpa penyeragaman, orang yang sama masuk sebagai baris yang berbeda hanya karena cara
 * menulisnya berbeda hari itu:
 *
 *     0812-3456-7890      ditulis pemilik saat mencatat kasbon
 *     +62 812 3456 7890   disalin dari kontak WhatsApp
 *     081234567890        diketik cepat di kasir
 *
 * Ketiganya orang yang SAMA dan ketiganya lolos aturan `unique`, karena bagi basis data
 * ketiganya teks yang berbeda. Akibatnya utang satu orang terpecah ke tiga baris: pemilik
 * menagih Rp 50.000 sementara yang benar Rp 150.000, dan selisihnya tidak muncul sebagai galat
 * di layar mana pun — ia muncul sebagai uang yang tidak pernah kembali.
 *
 * BENTUK BAKUNYA ANGKA SAJA BERAWALAN 0 (081234567890), bukan +62. Alasannya: itu bentuk yang
 * ditulis dan dibaca orang Indonesia di kertas, dan layar ini dibaca pemilik warung, bukan
 * sistem luar. Perubahan ke 62 untuk tautan WhatsApp dikerjakan SAAT menyusun tautannya
 * (`wa.me/62…`), bukan dengan menyimpannya begitu di basis data — menyimpan bentuk mesin
 * membuat setiap layar harus menerjemahkannya kembali, dan satu layar pasti lupa.
 *
 * YANG SENGAJA TIDAK DILAKUKAN:
 *
 *  - TIDAK menebak kode negara untuk nomor yang tidak berawalan 0/62/+62. Nomor rumah
 *    "0215551234" dan nomor asing tetap disimpan apa adanya (setelah pemisahnya dibuang).
 *    Menebak berarti mengarang nomor orang lain.
 *  - TIDAK memvalidasi bahwa nomornya benar-benar hidup. Itu bukan pekerjaan yang bisa
 *    dilakukan tanpa mengirim pesan, dan pemilik yang mencatat nomor salah ketik lebih baik
 *    menyimpannya daripada ditolak di tengah antrean.
 *  - TIDAK menolak nomor pendek. Beberapa pelanggan hanya meninggalkan nomor rumah 7 digit.
 *    Batas bawahnya cuma menahan yang jelas bukan nomor (satu-dua digit salah pencet).
 */
class NomorHp
{
    /**
     * Panjang paling pendek yang masih mungkin nomor sungguhan.
     *
     * Nomor rumah terpendek di Indonesia 7 digit (tanpa kode area). Di bawah itu hampir pasti
     * salah pencet, dan menyimpannya membuat pencarian nomor menemukan orang yang salah.
     */
    public const MIN_DIGIT = 7;

    /**
     * Batas atas mengikuti E.164 (15 digit termasuk kode negara).
     *
     * Bukan soal kerapian: kolomnya `string` tanpa batas, jadi tanpa ini satu tempelan 4.000
     * karakter masuk ke kolom nomor dan muncul di setiap baris tabel.
     */
    public const MAKS_DIGIT = 15;

    /**
     * Membakukan nomor. Mengembalikan null untuk masukan kosong.
     *
     * Yang dibuang: spasi, tanda hubung, titik, kurung — semua cara orang memisah angka.
     * Yang diubah: awalan +62 dan 62 menjadi 0.
     * Yang dibiarkan: sisanya, apa adanya.
     */
    public static function bakukan(?string $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        $angka = preg_replace('/[^0-9+]/', '', $nilai) ?? '';

        if ($angka === '') {
            return null;
        }

        /*
         * "+62" dan "62" menjadi "0" HANYA kalau sisanya berawalan 8.
         *
         * Syarat "berawalan 8" itu penjaganya, bukan hiasan. Semua nomor HP Indonesia dimulai
         * 8 sesudah kode negaranya, jadi "628123…" pasti kode negara. Tanpa syarat itu, nomor
         * yang kebetulan berawalan 62 — misalnya nomor mesin/ekstensi internal "621234567" —
         * ikut dipotong menjadi "01234567", dan nomor yang berubah sendiri jauh lebih buruk
         * daripada nomor yang tidak dirapikan.
         */
        if (preg_match('/^(?:\+62|62)(8\d+)$/', $angka, $cocok) === 1) {
            return '0'.$cocok[1];
        }

        // Plus yang tersisa (nomor asing, mis. +6591234567) dibuang tandanya saja; angkanya
        // tetap utuh supaya pemilik masih mengenali nomor yang ia tulis.
        return ltrim($angka, '+');
    }

    /**
     * Apakah bentuknya masuk akal sebagai nomor. Kosong dianggap SAH — kolomnya opsional,
     * dan "wajib diisi atau tidak" adalah keputusan pemanggilnya, bukan keputusan di sini.
     */
    public static function sah(?string $nilai): bool
    {
        $baku = self::bakukan($nilai);

        if ($baku === null) {
            return true;
        }

        // Huruf tidak pernah lolos: bakukan() sudah membuang apa pun selain digit, jadi yang
        // diperiksa di sini panjangnya. "abc" menjadi "" dan tertangkap sebagai kosong di
        // atas — itu sebabnya pemanggil TIDAK boleh memakai sah() sendirian untuk menyimpulkan
        // "nomornya terisi"; pakai bakukan() dan periksa hasilnya.
        $panjang = strlen($baku);

        return $panjang >= self::MIN_DIGIT && $panjang <= self::MAKS_DIGIT;
    }

    /**
     * Bentuk untuk tautan WhatsApp: 62 tanpa tanda plus (wa.me tidak menerima plus).
     *
     * Hanya nomor Indonesia berawalan 0 yang diubah. Nomor lain dikembalikan apa adanya —
     * memaksakan 62 di depan nomor asing membuat tautannya menunjuk orang yang tidak ada.
     */
    public static function untukWhatsapp(?string $nilai): ?string
    {
        $baku = self::bakukan($nilai);

        if ($baku === null) {
            return null;
        }

        return str_starts_with($baku, '0') ? '62'.substr($baku, 1) : $baku;
    }
}
