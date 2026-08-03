<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case BelumBayar = 'belum_bayar';
    case Lunas = 'lunas';
    case Telat = 'telat';

    public function label(): string
    {
        return match ($this) {
            self::BelumBayar => 'Belum Bayar',
            self::Lunas => 'Lunas',
            self::Telat => 'Telat',
        };
    }

    public function isOutstanding(): bool
    {
        return $this !== self::Lunas;
    }
}
