<?php

namespace Tests\Feature;

use App\Support\BacaCsv;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pembaca CSV — menerima berkas apa adanya dari orang, bukan CSV ideal.
 *
 * Tiga jebakan yang dijaga paling keras, dan ketiganya SUNYI: tidak ada galat, impornya
 * "berhasil", dan yang salah baru terlihat berhari-hari kemudian di layar kasir.
 *
 *  1. Excel Indonesia menyimpan CSV berpemisah TITIK KOMA, karena koma sudah dipakai sebagai
 *     koma desimal. Dibaca dengan pemisah koma, seluruh baris jadi satu kolom.
 *  2. Excel menaruh BOM di awal berkas, jadi judul kolom PERTAMA saja yang tidak cocok —
 *     gejalanya "kolom nama tidak ada" pada berkas yang jelas punya kolom nama paling kiri.
 *  3. Excel lama menyimpan dalam Windows-1252. Nama barang masuk sebagai byte rusak.
 */
class BacaCsvTest extends TestCase
{
    /* ── Pemisah ─────────────────────────────────────────────────────────── */

    #[Test]
    public function pemisah_titik_koma_dari_excel_indonesia_terbaca(): void
    {
        $hasil = BacaCsv::baca("nama;harga;satuan\nNasi Goreng;15000;porsi\n");

        $this->assertSame(['nama', 'harga', 'satuan'], $hasil['judul']);
        $this->assertSame('Nasi Goreng', $hasil['baris'][0]['isi']['nama']);
        $this->assertSame('15000', $hasil['baris'][0]['isi']['harga']);
    }

    #[Test]
    public function koma_di_dalam_nama_tidak_mengalahkan_pemisah_titik_koma(): void
    {
        /*
         * Inti kenapa pemisahnya ditebak dari BARIS JUDUL, bukan dari hitungan seluruh berkas.
         * Nama "Kopi, Susu, Gula" membuat koma menang telak kalau yang dihitung seluruh isi —
         * padahal berkasnya berpemisah titik koma dan komanya bagian dari nama barang.
         */
        $hasil = BacaCsv::baca("nama;harga\n\"Kopi, Susu, Gula\";12000\n");

        $this->assertSame('Kopi, Susu, Gula', $hasil['baris'][0]['isi']['nama']);
        $this->assertSame('12000', $hasil['baris'][0]['isi']['harga']);
    }

    #[Test]
    public function pemisah_koma_biasa_tetap_terbaca(): void
    {
        // Kontrol: penebakan yang selalu menjawab titik koma juga akan lolos uji di atas.
        $hasil = BacaCsv::baca("nama,harga\nTeh Manis,5000\n");

        $this->assertSame('Teh Manis', $hasil['baris'][0]['isi']['nama']);
        $this->assertSame('5000', $hasil['baris'][0]['isi']['harga']);
    }

    #[Test]
    public function pemisah_tab_terbaca(): void
    {
        // Bentuk yang muncul saat orang menyalin langsung dari Excel ke Notepad.
        $hasil = BacaCsv::baca("nama\tharga\nEs Teh\t4000\n");

        $this->assertSame('Es Teh', $hasil['baris'][0]['isi']['nama']);
    }

    /* ── BOM & encoding ──────────────────────────────────────────────────── */

    #[Test]
    public function bom_utf8_tidak_merusak_judul_kolom_pertama(): void
    {
        /*
         * Cacat yang paling membingungkan dari ketiganya, karena mata tidak bisa melihatnya:
         * judulnya bukan "nama" melainkan "\u{FEFF}nama", dan HANYA kolom pertama yang gagal
         * dicocokkan. Pemilik melihat "kolom nama tidak ada" pada berkas yang jelas punya
         * kolom nama di paling kiri.
         */
        $hasil = BacaCsv::baca("\xEF\xBB\xBFnama,harga\nNasi Uduk,10000\n");

        $this->assertSame(['nama', 'harga'], $hasil['judul']);
        $this->assertArrayHasKey('nama', $hasil['baris'][0]['isi']);
    }

    #[Test]
    public function teks_windows1252_diubah_jadi_utf8_bukan_dibiarkan_rusak(): void
    {
        // "Kopi Susu Gulá" dalam Windows-1252: á adalah satu byte 0xE1, yang bukan UTF-8 sah.
        $isi = "nama,harga\nKopi Susu Gul\xE1,12000\n";

        $hasil = BacaCsv::baca($isi);

        $this->assertTrue(mb_check_encoding($hasil['baris'][0]['isi']['nama'], 'UTF-8'));
        $this->assertSame('Kopi Susu Gulá', $hasil['baris'][0]['isi']['nama']);
    }

    #[Test]
    public function teks_utf8_yang_sudah_benar_tidak_ikut_diubah(): void
    {
        // Pagar untuk uji di atas: pembetulan yang berjalan tanpa syarat akan MERUSAK teks
        // UTF-8 yang sudah benar — dan itu jauh lebih sering daripada berkas Latin-1.
        $hasil = BacaCsv::baca("nama,harga\nKopi Susu Gulá,12000\n");

        $this->assertSame('Kopi Susu Gulá', $hasil['baris'][0]['isi']['nama']);
    }

    /* ── Judul kolom ─────────────────────────────────────────────────────── */

    #[Test]
    public function judul_kolom_diseragamkan_apa_pun_cara_mengetiknya(): void
    {
        // "Nama Produk", "nama_produk", dan "NAMA PRODUK" adalah kolom yang sama bagi siapa
        // pun yang membacanya, jadi ketiganya harus sama bagi aplikasinya juga.
        $this->assertSame('nama_produk', BacaCsv::bakukanJudul('Nama Produk'));
        $this->assertSame('nama_produk', BacaCsv::bakukanJudul('NAMA_PRODUK'));
        $this->assertSame('nama_produk', BacaCsv::bakukanJudul('  nama-produk  '));
    }

    #[Test]
    public function tanda_baca_di_judul_dibuang_tanpa_menebak_artinya(): void
    {
        /*
         * "Harga (Rp)" menjadi `harga_rp`, BUKAN `harga`, dan itu disengaja.
         *
         * Menebak bahwa "(Rp)" adalah hiasan berarti pembaca CSV ini mulai tahu arti kolom —
         * dan begitu ia tahu satu arti, ia harus tahu semuanya, lalu tidak bisa dipakai lagi
         * untuk impor berikutnya (pelanggan, bahan baku) yang kolomnya sama sekali berbeda.
         * Padanan nama kolom adalah aturan produk; tempatnya di aksi impor, bukan di sini.
         */
        $this->assertSame('harga_rp', BacaCsv::bakukanJudul('Harga (Rp)'));
        $this->assertSame('harga_jual', BacaCsv::bakukanJudul('Harga Jual*'));
    }

    #[Test]
    public function kolom_tanpa_judul_diabaikan_bukan_jadi_kunci_kosong(): void
    {
        // Kolom kosong di ujung kanan adalah bentuk yang sangat biasa dari Excel — sel yang
        // pernah diketik lalu dihapus tetap meninggalkan pemisahnya.
        $hasil = BacaCsv::baca("nama,harga,,\nNasi,10000,,\n");

        $this->assertSame(['nama', 'harga'], $hasil['judul']);
        $this->assertSame(['nama', 'harga'], array_keys($hasil['baris'][0]['isi']));
    }

    /* ── Bentuk baris ────────────────────────────────────────────────────── */

    #[Test]
    public function baris_pendek_tetap_punya_semua_kunci(): void
    {
        /*
         * Baris yang kolom terakhirnya dikosongkan sampai pemisahnya ikut hilang adalah
         * bentuk yang sangat biasa. Kunci yang HILANG (bukan kosong) memaksa setiap pembaca
         * memeriksa keberadaan tiap kolom di setiap tempat — dan satu tempat pasti lupa,
         * lalu melempar "undefined array key" di tengah impor 300 baris.
         */
        $hasil = BacaCsv::baca("nama,harga,barcode\nNasi,10000\n");

        $this->assertSame('', $hasil['baris'][0]['isi']['barcode']);
    }

    #[Test]
    public function spasi_di_sekitar_nilai_dibuang(): void
    {
        /*
         * DITAMBAHKAN SESUDAH UJI MUTASI: melepas trim() dari nilai sel tidak membuat satu
         * pun uji merah, jadi penjaganya cuma ada di kode dan tidak dijaga apa pun.
         *
         * Yang dilindungi bukan kerapian. Excel meninggalkan spasi di mana-mana — orang
         * mengetik "Nasi Goreng " lalu menekan Tab, dan menyalin dari WhatsApp membawa spasi
         * di depan. Tanpa trim, "15000 " ditolak pembaca uang sebagai harga yang tidak
         * terbaca, dan " pcs" ditolak sebagai satuan yang tidak dikenal — dua penolakan yang
         * TIDAK BISA DIJELASKAN siapa pun, karena yang membedakannya tidak terlihat di layar.
         */
        $hasil = BacaCsv::baca("nama,harga,satuan\n  Nasi Goreng  ,  15000, pcs \n");

        $this->assertSame('Nasi Goreng', $hasil['baris'][0]['isi']['nama']);
        $this->assertSame('15000', $hasil['baris'][0]['isi']['harga']);
        $this->assertSame('pcs', $hasil['baris'][0]['isi']['satuan']);
    }

    #[Test]
    public function baris_kosong_di_tengah_berkas_dilewati(): void
    {
        // Baris kosong di ujung berkas hampir selalu ada; di tengah pun biasa, karena orang
        // memberi jarak antar kelompok barang di Excel.
        $hasil = BacaCsv::baca("nama,harga\nNasi,10000\n\n\nTeh,5000\n");

        $this->assertCount(2, $hasil['baris']);
    }

    #[Test]
    public function nomor_baris_menunjuk_baris_yang_dilihat_orang_di_excel(): void
    {
        /*
         * Angka inilah yang muncul di pesan galat. Meleset satu membuat pemilik memperbaiki
         * baris yang salah — dan baris yang benar tetap ditolak pada percobaan berikutnya.
         * Baris 1 adalah judul, jadi data pertama adalah baris 2.
         */
        $hasil = BacaCsv::baca("nama,harga\nNasi,10000\nTeh,5000\n");

        $this->assertSame(2, $hasil['baris'][0]['nomor']);
        $this->assertSame(3, $hasil['baris'][1]['nomor']);
    }

    #[Test]
    public function akhir_baris_gaya_windows_tidak_meninggalkan_karakter_tersembunyi(): void
    {
        /*
         * Tanpa penyeragaman \r\n, nilai kolom TERAKHIR tiap baris membawa \r di ujungnya.
         * "porsi\r" bukan satuan yang dikenal enum mana pun, dan penolakannya tidak bisa
         * dijelaskan siapa pun karena \r tidak terlihat di layar.
         */
        $hasil = BacaCsv::baca("nama,satuan\r\nNasi Goreng,porsi\r\n");

        $this->assertSame('porsi', $hasil['baris'][0]['isi']['satuan']);
    }

    /* ── Batas ───────────────────────────────────────────────────────────── */

    #[Test]
    public function berkas_kelewat_panjang_dipotong_dan_dikabarkan(): void
    {
        /*
         * Impor berjalan di dalam satu permintaan HTTP. Berkas 50.000 baris menabrak batas
         * waktu di tengah jalan, meninggalkan sebagian produk masuk dan sebagian tidak —
         * tanpa ada yang tahu di baris mana berhentinya.
         */
        $isi = "nama,harga\n".str_repeat("Nasi,10000\n", 30);

        $hasil = BacaCsv::baca($isi, maksBaris: 10);

        $this->assertCount(10, $hasil['baris']);
        $this->assertTrue($hasil['terpotong'], 'pemotongan yang tidak dikabarkan sama saja dengan data hilang diam-diam');
    }

    #[Test]
    public function berkas_yang_pas_di_batas_tidak_dinyatakan_terpotong(): void
    {
        // Pagar untuk uji di atas: penanda yang selalu menyala membuat pemilik mengira
        // datanya hilang padahal utuh, lalu memecah berkasnya tanpa perlu.
        $isi = "nama,harga\n".str_repeat("Nasi,10000\n", 10);

        $hasil = BacaCsv::baca($isi, maksBaris: 10);

        $this->assertFalse($hasil['terpotong']);
    }

    #[Test]
    public function berkas_kosong_tidak_meledak(): void
    {
        $hasil = BacaCsv::baca('');

        $this->assertSame([], $hasil['judul']);
        $this->assertSame([], $hasil['baris']);
    }

    #[Test]
    public function berkas_berisi_judul_saja_menghasilkan_nol_baris(): void
    {
        // Bentuk yang muncul saat orang mengunduh templat lalu langsung mengunggahnya kembali.
        $hasil = BacaCsv::baca("nama,harga,satuan\n");

        $this->assertSame(['nama', 'harga', 'satuan'], $hasil['judul']);
        $this->assertSame([], $hasil['baris']);
    }
}
