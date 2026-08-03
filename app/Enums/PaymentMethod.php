<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Qris = 'qris';
    case Ewallet = 'ewallet';
    case Edc = 'edc';
    case Kasbon = 'kasbon';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Qris => 'QRIS',
            self::Ewallet => 'E-Wallet',
            self::Edc => 'Kartu (EDC)',
            self::Kasbon => 'Kasbon (Bayar Nanti)',
        };
    }

    /** Hanya pembayaran tunai yang mempengaruhi hitungan fisik laci kas. */
    public function affectsCashDrawer(): bool
    {
        return $this === self::Cash;
    }

    /** Kasbon membuat baris utang di credit_ledgers, bukan uang masuk. */
    public function createsReceivable(): bool
    {
        return $this === self::Kasbon;
    }
}
