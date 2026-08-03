<?php

namespace App\Enums;

enum CashMovementType: string
{
    case Penjualan = 'penjualan';
    case PengeluaranPettyCash = 'pengeluaran_petty_cash';
    case Setoran = 'setoran';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Penjualan => 'Penjualan',
            self::PengeluaranPettyCash => 'Pengeluaran Kas Kecil',
            self::Setoran => 'Setoran Modal',
            self::Lainnya => 'Lainnya',
        };
    }

    /** Arah uang terhadap laci kas: penjualan & setoran masuk, sisanya keluar. */
    public function isInflow(): bool
    {
        return in_array($this, [self::Penjualan, self::Setoran], true);
    }
}
