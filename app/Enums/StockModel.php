<?php

namespace App\Enums;

enum StockModel: string
{
    case Mandiri = 'mandiri';
    case Terpusat = 'terpusat';

    public function label(): string
    {
        return match ($this) {
            self::Mandiri => 'Stok Mandiri per Outlet',
            self::Terpusat => 'Stok Terpusat (Gudang Pusat)',
        };
    }

    /** Transfer stok antar outlet hanya relevan pada model terpusat. */
    public function usesTransfer(): bool
    {
        return $this === self::Terpusat;
    }
}
