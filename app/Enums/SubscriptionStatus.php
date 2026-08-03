<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Aktif = 'aktif';
    case GracePeriod = 'grace_period';
    case Suspend = 'suspend';
    case Berhenti = 'berhenti';

    public function label(): string
    {
        return match ($this) {
            self::Aktif => 'Aktif',
            self::GracePeriod => 'Masa Tenggang',
            self::Suspend => 'Suspend',
            self::Berhenti => 'Berhenti',
        };
    }

    public function isBillable(): bool
    {
        return in_array($this, [self::Aktif, self::GracePeriod], true);
    }
}
