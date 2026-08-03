<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Draft = 'draft';
    case Lunas = 'lunas';
    case BelumLunas = 'belum_lunas';
    case Void = 'void';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Lunas => 'Lunas',
            self::BelumLunas => 'Belum Lunas',
            self::Void => 'Void',
            self::Refund => 'Refund',
        };
    }

    /** Transaksi yang ikut dihitung sebagai omzet. */
    public function countsAsRevenue(): bool
    {
        return in_array($this, [self::Lunas, self::BelumLunas], true);
    }
}
