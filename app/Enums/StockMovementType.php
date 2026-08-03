<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Masuk = 'masuk';
    case Keluar = 'keluar';
    case Opname = 'opname';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::Masuk => 'Stok Masuk',
            self::Keluar => 'Stok Keluar',
            self::Opname => 'Stok Opname',
            self::Transfer => 'Transfer Antar Outlet',
        };
    }

    /**
     * Opname & transfer bisa menambah atau mengurangi, jadi tandanya ditentukan
     * dari nilai jumlah yang dicatat, bukan dari tipenya.
     */
    public function hasFixedDirection(): bool
    {
        return in_array($this, [self::Masuk, self::Keluar], true);
    }
}
