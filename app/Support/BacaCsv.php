<?php

namespace App\Support;

/**
 * Pembaca CSV yang menerima berkas APA ADANYA dari orang, bukan CSV ideal.
 *
 * KENAPA BERKAS INI ADA, dan kenapa ia bukan pembungkus tipis di atas `fgetcsv()`. Berkas
 * yang benar-benar sampai ke aplikasi ini datang dari Excel berbahasa Indonesia, dari Google
 * Sheets, dan dari WhatsApp — dan ketiganya menghasilkan bentuk yang berbeda. Tiga jebakan
 * di bawah ini semuanya SUNYI: tidak ada galat, impornya "berhasil", dan yang salah baru
 * terlihat berhari-hari kemudian di layar kasir.
 *
 *  1. PEMISAH TITIK KOMA. Excel dengan setelan wilayah Indonesia menyimpan CSV memakai `;`,
 *     bukan `,` — karena koma sudah dipakai sebagai koma desimal. Dibaca dengan pemisah koma,
 *     SELURUH baris jadi satu kolom: judulnya tidak dikenali, dan impornya menolak berkas
 *     yang sebenarnya benar. Pemilik menyimpulkan aplikasinya tidak bisa membaca file Excel.
 *
 *  2. BOM UTF-8. Excel menaruh tiga byte EF BB BF di awal berkas. Judul kolom pertama karena
 *     itu bukan "nama" melainkan "\u{FEFF}nama" — mata tidak bisa membedakannya, dan
 *     pencocokan judul gagal HANYA pada kolom pertama. Gejalanya: "kolom nama tidak ada" pada
 *     berkas yang jelas-jelas punya kolom nama di paling kiri.
 *
 *  3. BUKAN UTF-8. Excel lama menyimpan dalam Windows-1252/ISO-8859-1. Nama barang yang
 *     memakai huruf beraksen atau simbol masuk sebagai byte rusak; MySQL utf8mb4 menolaknya
 *     atau memotongnya, dan nama produk berakhir setengah.
 *
 * YANG SENGAJA TIDAK DIURUS DI SINI: arti kolomnya. Kelas ini mengembalikan baris-baris
 * berkunci judul, titik. Aturan "kolom nama wajib", "harga dibaca App\Support\Uang", dan
 * "SKU kembar ditolak" adalah aturan produk, bukan aturan CSV — menaruhnya di sini membuat
 * pembaca ini tidak bisa dipakai untuk impor berikutnya (pelanggan, bahan baku).
 */
class BacaCsv
{
    /**
     * Batas baris yang dibaca sekali jalan.
     *
     * Bukan angka acak: impor dijalankan di dalam satu permintaan HTTP, dan berkas 50.000
     * baris akan menabrak batas waktu di tengah jalan — meninggalkan sebagian produk masuk
     * dan sebagian tidak, tanpa ada yang tahu di baris mana berhentinya. Lebih baik menolak
     * di depan dengan angka yang jelas.
     */
    public const MAKS_BARIS = 2000;

    /** Pemisah yang dicoba, berurut sesuai seberapa sering ia benar-benar muncul. */
    private const PEMISAH = [',', ';', "\t", '|'];

    /**
     * Menebak pemisah dari BARIS JUDUL.
     *
     * Yang dipakai bukan "mana yang paling banyak muncul di seluruh berkas", melainkan mana
     * yang menghasilkan KOLOM PALING BANYAK di baris judul. Bedanya menentukan benar atau
     * salah pada berkas yang isinya mengandung tanda baca: nama barang "Kopi, Susu, Gula"
     * membuat koma menang telak pada hitungan seluruh berkas, padahal berkasnya berpemisah
     * titik koma dan koma itu bagian dari nama.
     */
    public static function tebakPemisah(string $barisJudul): string
    {
        $terbaik = ',';
        $terbanyak = 0;

        foreach (self::PEMISAH as $pemisah) {
            $jumlah = count(str_getcsv($barisJudul, $pemisah, '"', '\\'));

            if ($jumlah > $terbanyak) {
                $terbanyak = $jumlah;
                $terbaik = $pemisah;
            }
        }

        return $terbaik;
    }

    /**
     * Membuang BOM UTF-8 dan membetulkan teks yang bukan UTF-8.
     *
     * `mb_check_encoding` dipakai sebagai penentu, bukan `mb_detect_encoding`: yang kedua
     * MENEBAK dan hampir selalu menjawab "UTF-8" untuk teks Latin-1 pendek, sehingga
     * pembetulannya tidak pernah berjalan justru pada berkas yang membutuhkannya.
     */
    public static function bersihkan(string $isi): string
    {
        $isi = preg_replace('/^\xEF\xBB\xBF/', '', $isi) ?? $isi;

        if (! mb_check_encoding($isi, 'UTF-8')) {
            // Windows-1252, bukan ISO-8859-1: Excel di Windows memakai yang pertama, dan
            // keduanya berbeda persis di rentang yang berisi tanda kutip miring dan tanda
            // hubung panjang — dua karakter yang paling sering ikut tersalin dari Word.
            $isi = mb_convert_encoding($isi, 'UTF-8', 'Windows-1252');
        }

        return $isi;
    }

    /**
     * Menyeragamkan judul kolom supaya pencocokannya tidak bergantung cara orang mengetik.
     *
     * "Nama Produk", "nama_produk", dan "NAMA PRODUK" adalah kolom yang sama bagi siapa pun
     * yang membacanya, jadi ketiganya harus sama bagi aplikasinya juga. Kalau tidak, pemilik
     * yang judul kolomnya berbeda satu spasi mendapat pesan "kolom nama tidak ada" untuk
     * berkas yang jelas punya kolom itu.
     */
    public static function bakukanJudul(string $judul): string
    {
        $judul = self::bersihkan($judul);
        $judul = mb_strtolower(trim($judul));

        // Spasi, tanda hubung, dan garis bawah dianggap sama; sisanya dibuang.
        $judul = preg_replace('/[\s\-]+/u', '_', $judul) ?? $judul;

        return trim(preg_replace('/[^a-z0-9_]/u', '', $judul) ?? $judul, '_');
    }

    /**
     * Membaca isi CSV menjadi baris-baris berkunci judul.
     *
     * @return array{judul: array<int, string>, baris: array<int, array{nomor: int, isi: array<string, string>}>, terpotong: bool}
     */
    public static function baca(string $isi, int $maksBaris = self::MAKS_BARIS): array
    {
        $isi = self::bersihkan($isi);

        // \r\n dan \r (Excel di Mac lama) diseragamkan lebih dulu: tanpa ini, nilai kolom
        // terakhir tiap baris membawa \r di ujungnya — "pcs\r" bukan satuan yang dikenal
        // enum mana pun, dan penolakannya tidak bisa dijelaskan karena \r tidak terlihat.
        $isi = str_replace(["\r\n", "\r"], "\n", $isi);

        $barisMentah = array_values(array_filter(
            explode("\n", $isi),
            fn (string $b) => trim($b) !== '',
        ));

        if ($barisMentah === []) {
            return ['judul' => [], 'baris' => [], 'terpotong' => false];
        }

        $pemisah = self::tebakPemisah($barisMentah[0]);

        $judul = array_map(
            self::bakukanJudul(...),
            str_getcsv(array_shift($barisMentah), $pemisah, '"', '\\'),
        );

        $baris = [];
        $terpotong = false;

        foreach ($barisMentah as $i => $teks) {
            if (count($baris) >= $maksBaris) {
                $terpotong = true;

                break;
            }

            $nilai = str_getcsv($teks, $pemisah, '"', '\\');
            $isiBaris = [];

            foreach ($judul as $kolom => $namaKolom) {
                if ($namaKolom === '') {
                    continue;
                }

                // Kolom yang tidak ada di baris ini diisi teks kosong, BUKAN dilewati:
                // baris pendek (kolom terakhir dikosongkan lalu komanya ikut hilang) adalah
                // bentuk yang sangat biasa, dan kunci yang hilang membuat pembacanya harus
                // memeriksa keberadaan tiap kolom di setiap tempat.
                $isiBaris[$namaKolom] = trim((string) ($nilai[$kolom] ?? ''));
            }

            // +2: satu untuk baris judul yang sudah diambil, satu karena manusia menghitung
            // baris mulai dari 1. Angka inilah yang muncul di pesan galat, dan angka yang
            // meleset satu membuat pemilik memperbaiki baris yang salah.
            $baris[] = ['nomor' => $i + 2, 'isi' => $isiBaris];
        }

        return ['judul' => array_values(array_filter($judul)), 'baris' => $baris, 'terpotong' => $terpotong];
    }
}
