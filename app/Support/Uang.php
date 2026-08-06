<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Penerjemah angka yang DIKETIK ORANG menjadi angka yang dipakai aplikasi.
 *
 * Kenapa berkas ini ada — cacat yang benar-benar terjadi:
 *
 *   Pemilik warung mengetik "58.000" di kolom harga beli (titik ribuan, kebiasaan menulis
 *   rupiah di Indonesia). `is_numeric('58.000') === true` dan `(float) '58.000' === 58.0`,
 *   jadi nilainya LOLOS aturan `numeric|min:0` tanpa satu pun galat dan tersimpan sebagai
 *   Rp 58. Nota Rp 116.000 tercatat Rp 115 sesudah diskon "1.000" (= Rp 1), dan harga beli
 *   di master produk ikut tertimpa jadi 58. Tidak ada yang merah, tidak ada yang aneh di
 *   layar; yang salah baru terbaca berbulan kemudian saat pemilik menghitung margin.
 *
 * Dua jenis kolom, dua aturan yang BERLAWANAN, dan itu bukan kelalaian:
 *
 *   - UANG (`sah()` / `baca()`) — titik = ribuan. Rupiah yang diketik orang tidak punya sen:
 *     tidak ada yang mengetik "58.50" di kolom harga barang warung. Bentuk berdesimal
 *     DITOLAK, bukan ditebak.
 *   - JUMLAH (`bacaJumlah()`) — pecahan justru sah: 2,5 kg beras, 1,5 liter minyak. Di sini
 *     yang ditolak adalah bentuk BERGOLONGAN ("1.500"), karena 1,5 kg dan 1.500 kg berbeda
 *     seribu kali.
 *
 * PERINGATAN untuk yang berikutnya: jangan "merapikan" berkas ini menjadi `(float) $nilai`
 * atau `str_replace('.', '', …)` tanpa syarat. `(float)` adalah cacat di atas; `str_replace`
 * tanpa syarat mengubah "58.5" (lima puluh delapan setengah) menjadi 585. Menolak adalah
 * keputusan, bukan kemalasan: satu-satunya cara membedakan titik ribuan dari titik desimal
 * adalah MENEBAK, dan menebak uang orang lain tidak boleh pernah senyap.
 *
 * Yang TIDAK diurus di sini: pemformatan untuk ditampilkan (milik Blade/JS) dan aturan
 * domain seperti "harga wajib diisi" atau "jumlah harus lebih dari nol" (milik Action /
 * komponen yang memanggilnya).
 */
class Uang
{
    /** Rupiah bulat tanpa pemisah: "58000", "0", "1000000". */
    private const POLA_BULAT = '/^\d+$/';

    /**
     * Titik ribuan bergolongan tiga: "58.000", "1.000.000", "999.999.999".
     *
     * Golongan pertama 1–3 digit, sisanya WAJIB tepat 3 digit. Itulah yang membuat "58.00"
     * (dua digit) dan "1.23.456" (golongan tengah dua digit) tidak lolos ke sini — keduanya
     * bukan cara orang menulis rupiah, jadi keduanya salah ketik yang harus terlihat.
     */
    private const POLA_RIBUAN = '/^\d{1,3}(\.\d{3})+$/';

    /**
     * Pecahan untuk KUANTITAS, satu pemisah saja: "2,5", "2.5", "0,25", "12,75".
     *
     * Bagian bulatnya bebas panjang dan itu aman, karena bentuk bergolongan sudah ditolak
     * LEBIH DULU di bacaJumlah() — lihat alasan urutannya di sana.
     */
    private const POLA_PECAHAN = '/^\d+[.,]\d+$/';

    /**
     * Panjang digit maksimum yang dibaca sebagai uang.
     *
     * Bukan aturan bisnis, melainkan pagar terhadap keluapan yang SENYAP:
     * `(int) '99999999999999999999'` menjadi PHP_INT_MAX tanpa satu pun galat. Nilai yang
     * dikarang mesin lebih buruk daripada penolakan yang bisa dibaca orang.
     */
    private const MAKS_DIGIT = 15;

    /**
     * Angka rupiah yang diketik orang: sah atau tidak.
     *
     * Kosong dianggap SAH — "belum diisi" bukan "salah ketik". Yang memutuskan apakah kosong
     * boleh (diskon kosong = 0) atau tidak boleh (harga kosong = wajib) adalah pemanggilnya,
     * karena jawabannya berbeda per kolom.
     */
    public static function sah(mixed $nilai): bool
    {
        try {
            self::baca($nilai);

            return true;
        } catch (InvalidArgumentException) {
            // Satu jalan penerjemahan untuk sah() dan baca(). Dua salinan aturan yang sama
            // pasti bercabang suatu hari, dan cabangnya berarti sebuah nilai dinyatakan sah
            // lalu gagal dibaca — kegagalan yang muncul di tengah penyimpanan.
            return false;
        }
    }

    /**
     * Rupiah sebagai bilangan BULAT; null kalau kosong.
     *
     * Bulat (int), bukan float, karena rupiah yang diketik orang tidak punya sen. Hasil
     * HITUNGAN tetap boleh berdesimal — harga per satuan dasar (116.000 ÷ 24 pcs = 4.833,33)
     * dihitung di TerimaPembelianAction dan disimpan di kolom decimal(15,2). Yang dibatasi
     * di sini HANYA angka yang diketik.
     *
     * Melempar, bukan mengembalikan null, untuk nilai tidak sah: null di pemanggil yang
     * memperlakukannya sebagai "kosong" akan menjadi 0 — tepat bentuk kegagalan senyap yang
     * berkas ini ada untuk mencegahnya.
     *
     * @throws InvalidArgumentException kalau bentuknya tidak bisa dibaca
     */
    public static function baca(mixed $nilai): ?int
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        if (is_bool($nilai)) {
            // true tidak berarti Rp 1. Boolean di kolom uang selalu salah kirim.
            self::tolak($nilai);
        }

        if (is_int($nilai)) {
            // Minus ditolak: uang negatif tidak punya arti di aplikasi ini. Retur dan
            // koreksi punya jalurnya sendiri (pembatalan nota), bukan tanda minus di kolom
            // nominal — di sana minus selalu salah ketik.
            return $nilai < 0 ? self::tolak($nilai) : $nilai;
        }

        if (is_float($nilai)) {
            if (is_nan($nilai) || is_infinite($nilai) || $nilai < 0) {
                // NAN/INF lolos is_numeric tapi merusak setiap hitungan sesudahnya tanpa
                // satu pun pesan.
                self::tolak($nilai);
            }

            // Float BULAT diterima (58.0 → 58): angka yang sama, hanya lewat tipe lain —
            // seeder dan hitungan internal mengirimnya begitu.
            //
            // Float BERPECAHAN ditolak (58.5), walaupun matematikanya jelas: satu-satunya
            // cara ia sampai ke kolom uang adalah `(float)` di tempat yang seharusnya
            // memakai kelas ini, atau harga bersen yang tidak pernah diketik orang.
            // Menerima lalu membulatkannya menyembunyikan keduanya.
            return fmod($nilai, 1.0) === 0.0 ? (int) $nilai : self::tolak($nilai);
        }

        if (! is_string($nilai)) {
            // Array/objek/resource di kolom uang berarti muatannya salah bentuk.
            self::tolak($nilai);
        }

        $teks = self::lucutiRupiah(self::bersihkan($nilai));

        if ($teks === '') {
            // Hanya spasi/NBSP/"Rp" — sama artinya dengan kosong, bukan salah ketik.
            return null;
        }

        if (preg_match(self::POLA_BULAT, $teks) === 1) {
            return strlen($teks) > self::MAKS_DIGIT ? self::tolak($nilai) : (int) $teks;
        }

        if (preg_match(self::POLA_RIBUAN, $teks) === 1) {
            // Titik ribuan DIBUANG, bukan diartikan desimal: "58.000" → 58000.
            $angka = str_replace('.', '', $teks);

            return strlen($angka) > self::MAKS_DIGIT ? self::tolak($nilai) : (int) $angka;
        }

        /*
         * Semua sisanya DITOLAK, dengan alasannya masing-masing:
         *
         * - "58,5" dan "58.5" → sen. Rupiah yang diketik orang tidak punya sen, dan
         *   membacanya sebagai 58 (dipotong) atau 59 (dibulatkan) sama-sama menebak.
         * - "58.00" → mirip format "dua desimal" dari spreadsheet, tapi bisa juga 58.000
         *   yang kehilangan satu nol saat diketik. 58 atau 58.000? Beda seribu kali; tidak
         *   ada jawaban yang benar tanpa bertanya kepada orangnya.
         * - "1.23.456" → golongan tidak beraturan, jadi salah ketik. Membacanya sebagai
         *   123456 "memperbaiki" angka yang tidak pernah dimaksudkan siapa pun.
         * - "-58000" → uang negatif; lihat alasan di cabang int di atas.
         * - "58rb", "lima puluh", "58-000" → huruf & pemisah lain: menebak.
         *
         * Yang JUSTRU diterima dan perlu disebut supaya tidak ikut "dirapikan": spasi
         * sebagai pemisah ribuan ("58 000" → 58000). Spasi tidak pernah berarti koma
         * desimal di mana pun, jadi membuangnya bukan menebak — beda dari titik.
         */
        return self::tolak($nilai);
    }

    /**
     * KUANTITAS (kg, liter, pcs, dus) — bukan uang. Pecahan sah, titik ribuan tidak.
     *
     * Urutan pemeriksaannya MENENTUKAN, dan salah urutan di sini merugikan tanpa galat:
     *
     *   1. Bentuk bergolongan ("1.500", "1.000.000") ditolak DULU. Kalau desimal diperiksa
     *      lebih dulu, "1.500" terbaca 1,5 — dan orang yang baru belajar "titik boleh di
     *      kolom harga" akan menulis 1.500 di kolom jumlah lalu menerima 1,5 kg beras tanpa
     *      satu pun peringatan. Seribu kali lebih sedikit daripada yang ia maksud.
     *   2. Baru sesudah itu bentuk pecahan ("2,5" dan "2.5") dibaca sebagai 2,5.
     *
     * Koma WAJIB diterima: papan tombol dan kebiasaan menulis di Indonesia memakai koma
     * untuk desimal. Menolaknya berarti pemilik warteg yang membeli 2,5 kg beras tidak bisa
     * mencatat notanya sama sekali — itu keadaan sebelum berkas ini ada.
     *
     * Minus DIBIARKAN LEWAT di sini, berbeda dari uang. Alasannya: arti "jumlah minus"
     * berbeda per layar (di nota belanja itu salah ketik dan ditolak dengan pesannya
     * sendiri, "Jumlah beli harus lebih dari nol"; di koreksi stok minus justru sah), jadi
     * keputusannya milik pemanggil. Yang salah BENTUKNYA tetap ditolak di sini.
     *
     * @throws InvalidArgumentException kalau bentuknya tidak bisa dibaca
     */
    public static function bacaJumlah(mixed $nilai): ?float
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        if (is_bool($nilai)) {
            self::tolak($nilai);
        }

        if (is_int($nilai)) {
            return (float) $nilai;
        }

        if (is_float($nilai)) {
            // Float dari kode (seeder, hitungan lain) sudah berupa angka — tidak ada
            // titik/koma yang perlu ditafsirkan. Pecahan memang sah untuk kuantitas.
            if (is_nan($nilai) || is_infinite($nilai)) {
                self::tolak($nilai);
            }

            return $nilai;
        }

        if (! is_string($nilai)) {
            self::tolak($nilai);
        }

        // Tanpa pelucutan "Rp": itu hiasan kolom UANG. "Rp2" di kolom jumlah berarti isinya
        // tertukar kolom, dan itu harus terlihat.
        $teks = self::bersihkan($nilai);

        if ($teks === '') {
            return null;
        }

        // Minusnya dilepas dulu supaya tiap pola di bawah tidak perlu mengulangnya.
        $negatif = str_starts_with($teks, '-');
        $angka = $negatif ? substr($teks, 1) : $teks;

        // (1) BERGOLONGAN LEBIH DULU. Inilah yang menjaga "1.500" tidak pernah jadi 1,5.
        if (preg_match(self::POLA_RIBUAN, $angka) === 1) {
            self::tolak($nilai);
        }

        if (preg_match(self::POLA_BULAT, $angka) === 1) {
            $hasil = (float) $angka;
        } elseif (preg_match(self::POLA_PECAHAN, $angka) === 1) {
            // (2) Baru pecahan. Koma disamakan dengan titik supaya (float) membacanya:
            //     "2,5" dan "2.5" sama-sama dua setengah.
            $hasil = (float) str_replace(',', '.', $angka);
        } else {
            $hasil = self::tolak($nilai);
        }

        return $negatif ? -$hasil : $hasil;
    }

    /**
     * Membuang spasi dan sepupunya: NBSP, narrow NBSP, figure space, tab.
     *
     * NBSP (U+00A0) disebut khusus karena ia ADA di dunia nyata: `number_format` gaya
     * Indonesia, tempel-salin dari WhatsApp/spreadsheet, dan beberapa papan tombol ponsel
     * menghasilkannya. `trim()` biasa TIDAK membuangnya, jadi "Rp 58.000" bernbsp gagal
     * dibaca padahal bentuknya benar — dan penolakan yang tidak bisa dijelaskan membuat
     * orang berhenti memercayai kolomnya.
     */
    private static function bersihkan(string $teks): string
    {
        return str_replace(
            ["\u{00A0}", "\u{202F}", "\u{2007}", ' ', "\t", "\r", "\n"],
            '',
            $teks,
        );
    }

    /** Awalan "Rp"/"Rp." di kolom uang: hiasan, bukan bagian angkanya. */
    private static function lucutiRupiah(string $teks): string
    {
        return preg_replace('/^rp\.?/i', '', $teks) ?? $teks;
    }

    /**
     * Satu tempat penolakan, supaya pesannya selalu menyebut nilai MENTAHNYA.
     *
     * Pesan tanpa nilai mentah ("format tidak sah") membuat orang mengetik ulang hal yang
     * sama; yang menolong adalah melihat apa yang terbaca aplikasi.
     *
     * @throws InvalidArgumentException selalu — nilainya tidak pernah kembali
     */
    private static function tolak(mixed $nilai): mixed
    {
        throw new InvalidArgumentException('Bukan angka yang bisa dibaca: '.self::mentah($nilai));
    }

    /** Nilai mentah untuk pesan galat — supaya orang tahu APA yang ditolak. */
    private static function mentah(mixed $nilai): string
    {
        if ($nilai === null) {
            return 'kosong';
        }

        if (is_bool($nilai)) {
            return $nilai ? 'true' : 'false';
        }

        if (is_scalar($nilai)) {
            return '"'.$nilai.'"';
        }

        return is_array($nilai) ? 'daftar nilai' : get_debug_type($nilai);
    }
}
