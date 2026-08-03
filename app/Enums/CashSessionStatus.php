<?php

namespace App\Enums;

enum CashSessionStatus: string
{
    case Terbuka = 'terbuka';
    case Ditutup = 'ditutup';

    public function label(): string
    {
        return match ($this) {
            self::Terbuka => 'Terbuka',
            self::Ditutup => 'Ditutup',
        };
    }
}
