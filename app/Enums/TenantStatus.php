<?php

namespace App\Enums;

enum TenantStatus: string
{
    case Trial = 'trial';
    case Aktif = 'aktif';
    case Suspend = 'suspend';
    case Nonaktif = 'nonaktif';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Trial',
            self::Aktif => 'Aktif',
            self::Suspend => 'Suspend',
            self::Nonaktif => 'Nonaktif',
        };
    }

    /**
     * Saat suspend, merchant tidak bisa transaksi tapi datanya TETAP tersimpan
     * (tidak dihapus) — alur 2.3 dokumen bisnis.
     */
    public function canTransact(): bool
    {
        return in_array($this, [self::Trial, self::Aktif], true);
    }
}
