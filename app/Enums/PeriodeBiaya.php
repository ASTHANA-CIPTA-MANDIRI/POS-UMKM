<?php

namespace App\Enums;

/**
 * Seberapa sering satu biaya operasional berulang.
 *
 * PEMBAGINYA SENGAJA ANGKA BULAT (30, 7, 365), bukan jumlah hari yang sebenarnya di bulan
 * berjalan, dan itu keputusan sadar dengan harga yang diketahui.
 *
 * Pemilik warung yang menghitung sewa Rp 1.500.000 sebulan akan menulis "50.000 sehari" di
 * kertasnya — ia membagi 30, bukan 31 atau 28. Aplikasi yang menjawab Rp 48.387 di bulan
 * Januari dan Rp 53.571 di bulan Februari untuk sewa yang TIDAK BERUBAH akan dianggap salah
 * hitung, dan angka yang tidak dipercaya tidak dipakai.
 *
 * Harganya: 12 x 30 = 360 hari, jadi jumlah setahun meleset sekitar 1,4% dari pembagi
 * tahunan. Untuk angka perencanaan warung, selisih itu jauh lebih kecil daripada ketidak-
 * percayaan yang ditimbulkan angka yang berubah-ubah tiap bulan.
 */
enum PeriodeBiaya: string
{
    case Harian = 'harian';
    case Mingguan = 'mingguan';
    case Bulanan = 'bulanan';
    case Tahunan = 'tahunan';

    public function label(): string
    {
        return match ($this) {
            self::Harian => 'Per hari',
            self::Mingguan => 'Per minggu',
            self::Bulanan => 'Per bulan',
            self::Tahunan => 'Per tahun',
        };
    }

    /** Berapa hari yang dicakup satu kali biaya ini. */
    public function hari(): int
    {
        return match ($this) {
            self::Harian => 1,
            self::Mingguan => 7,
            self::Bulanan => 30,
            self::Tahunan => 365,
        };
    }

    /** Nominal yang jatuh ke satu hari. */
    public function perHari(float $nominal): float
    {
        return round($nominal / $this->hari(), 2);
    }
}
