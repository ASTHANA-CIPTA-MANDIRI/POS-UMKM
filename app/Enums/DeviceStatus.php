<?php

namespace App\Enums;

enum DeviceStatus: string
{
    case Aktif = 'aktif';
    case RusakKlaim = 'rusak_klaim';
    case Hilang = 'hilang';
    case Ditarik = 'ditarik';

    public function label(): string
    {
        return match ($this) {
            self::Aktif => 'Aktif Dipakai',
            self::RusakKlaim => 'Rusak - Dalam Klaim',
            self::Hilang => 'Hilang',
            self::Ditarik => 'Ditarik Kembali',
        };
    }

    public function canBeUsedForLogin(): bool
    {
        return $this === self::Aktif;
    }
}
