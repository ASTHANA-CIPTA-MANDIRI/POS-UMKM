<?php

namespace App\Enums;

/**
 * Alasan selisih stok saat opname / penyesuaian manual.
 *
 * Sengaja daftar tertutup, bukan teks bebas: satu-satunya gunanya mencatat alasan adalah
 * supaya bisa DIHITUNG belakangan ("bulan ini berapa yang hilang, berapa yang kedaluwarsa").
 * Teks bebas selalu berakhir sebagai "rusak", "pecah", "bocor", dan "rusak lagi" untuk hal
 * yang sama, dan sesudah itu tidak ada laporan yang bisa disusun darinya.
 */
enum AlasanOpname: string
{
    /** Saldo pertama kali diisi — bukan selisih, jadi tidak boleh dihitung sebagai kerugian. */
    case StokAwal = 'stok_awal';

    case Rusak = 'rusak';

    case Kadaluarsa = 'kadaluarsa';

    case Hilang = 'hilang';

    /** Barangnya utuh, catatannya yang salah (salah input, dobel catat). */
    case SalahCatat = 'salah_catat';

    /** Fisik lebih banyak daripada catatan. */
    case TemuanLebih = 'temuan_lebih';

    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::StokAwal => 'Stok Awal',
            self::Rusak => 'Rusak',
            self::Kadaluarsa => 'Kedaluwarsa',
            self::Hilang => 'Hilang',
            self::SalahCatat => 'Salah Catat',
            self::TemuanLebih => 'Temuan Lebih',
            self::Lainnya => 'Lainnya',
        };
    }

    /**
     * Hanya "lainnya" yang mewajibkan catatan.
     *
     * Kalau semua alasan mewajibkan catatan, isiannya akan diisi "-" oleh orang yang
     * sedang menghitung 200 barang, dan kolom catatan berubah jadi ritual tanpa isi.
     * "Lainnya" tanpa penjelasan tidak berarti apa-apa sama sekali — itu satu-satunya
     * pilihan yang benar-benar butuh kalimat.
     */
    public function butuhCatatan(): bool
    {
        return $this === self::Lainnya;
    }

    /** Untuk mengisi pilihan di formulir opname. */
    public static function pilihan(): array
    {
        return array_map(
            fn (self $alasan) => ['nilai' => $alasan->value, 'label' => $alasan->label()],
            self::cases(),
        );
    }
}
