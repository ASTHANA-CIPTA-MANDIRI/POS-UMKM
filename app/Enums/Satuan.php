<?php

namespace App\Enums;

enum Satuan: string
{
    case Pcs = 'pcs';
    case Kg = 'kg';
    case Liter = 'liter';
    case Dus = 'dus';
    case Porsi = 'porsi';

    public function label(): string
    {
        return match ($this) {
            self::Pcs => 'Pcs',
            self::Kg => 'Kg',
            self::Liter => 'Liter',
            self::Dus => 'Dus',
            self::Porsi => 'Porsi',
        };
    }

    /** Satuan pecahan boleh desimal (0,5 kg); satuan butiran tidak. */
    public function allowsFraction(): bool
    {
        return in_array($this, [self::Kg, self::Liter], true);
    }
}
