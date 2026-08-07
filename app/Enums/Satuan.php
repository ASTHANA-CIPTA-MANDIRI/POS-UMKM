<?php

namespace App\Enums;

/**
 * Satuan barang & bahan baku.
 *
 * Daftarnya SENGAJA pendek, dan itu keputusan yang harus dipertahankan: setiap case di
 * sini ikut muncul di dropdown satuan PRODUK juga. Dropdown 12 satuan di layar 390px harus
 * digulir untuk dicari, dan satuan yang harus dicari dengan menggulir sama saja tidak ada —
 * yang dipilih orang lalu menjadi yang pertama terlihat, bukan yang benar.
 *
 * Yang sudah DITOLAK, supaya tidak diusulkan lagi: `butir`, `ekor`, `tabung`, `bungkus`.
 * Semuanya nama lain untuk `Pcs` (seeder warteg sudah memakai `Pcs` untuk telur), jadi
 * masing-masing hanya menambah satu baris dropdown tanpa satu pun hitungan baru.
 *
 * Urutannya juga bukan selera: pasangan besar-kecil didekatkan (kg–gram, liter–ml) supaya
 * orang yang mencari "gram" menemukannya di sebelah "kg", bukan di ujung daftar.
 */
enum Satuan: string
{
    case Pcs = 'pcs';
    case Kg = 'kg';
    case Gram = 'gram';
    case Liter = 'liter';
    case Ml = 'ml';
    case Ikat = 'ikat';
    case Dus = 'dus';
    case Porsi = 'porsi';

    public function label(): string
    {
        return match ($this) {
            self::Pcs => 'Pcs',
            self::Kg => 'Kg',
            self::Gram => 'Gram',
            self::Liter => 'Liter',
            self::Ml => 'ml',
            self::Ikat => 'Ikat',
            self::Dus => 'Dus',
            self::Porsi => 'Porsi',
        };
    }

    /**
     * Satuan pecahan boleh desimal (0,5 kg); satuan butiran tidak.
     *
     * `gram`, `ml`, dan `ikat` sengaja FALSE, dan untuk dua yang pertama itu justru
     * ALASAN keberadaannya. Keduanya ada supaya pemilik yang lebih suka mengetik "250"
     * daripada "0,25" bisa menyetel satuan BAHANNYA gram — lalu seluruh rantai (nota
     * belanja, hitung stok, resep) berbahasa gram tanpa satu pun konversi. Itu jawaban
     * yang benar untuk risiko salah 1000×: tanpa konversi, tidak ada tempat untuk salah
     * kali seribu. Kalau gram lalu menerima pecahan ("0,25 gram"), kolomnya kembali
     * menerima bentuk yang mau dihindari dan alasannya hilang.
     *
     * `ikat` (kangkung, bayam, sawi) tidak punya padanan lain, dan setengah ikat tidak
     * pernah dibeli di pasar.
     */
    public function allowsFraction(): bool
    {
        return in_array($this, [self::Kg, self::Liter], true);
    }
}
