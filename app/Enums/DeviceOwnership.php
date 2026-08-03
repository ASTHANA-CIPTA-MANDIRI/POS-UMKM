<?php

namespace App\Enums;

enum DeviceOwnership: string
{
    case MilikPlatform = 'milik_platform';
    case MilikMerchant = 'milik_merchant';

    public function label(): string
    {
        return match ($this) {
            self::MilikPlatform => 'Milik Platform (Disewakan)',
            self::MilikMerchant => 'Milik Merchant (BYOD)',
        };
    }

    /** Deposit & kontrak sewa hanya berlaku untuk perangkat milik platform. */
    public function requiresDeposit(): bool
    {
        return $this === self::MilikPlatform;
    }
}
