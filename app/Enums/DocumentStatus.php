<?php

namespace App\Enums;

/**
 * Status alur dokumen gudang — dipakai bersama oleh purchase_orders dan
 * stock_transfers karena keduanya mengikuti siklus draft → kirim → terima.
 */
enum DocumentStatus: string
{
    case Draft = 'draft';
    case Dikirim = 'dikirim';
    case Diterima = 'diterima';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Dikirim => 'Dikirim',
            self::Diterima => 'Diterima',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    /** Stok baru bergerak saat dokumen diterima. */
    public function movesStock(): bool
    {
        return $this === self::Diterima;
    }
}
