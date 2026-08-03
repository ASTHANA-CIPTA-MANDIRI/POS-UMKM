<?php

namespace App\Enums;

enum BusinessType: string
{
    case Fnb = 'fnb';
    case Kelontong = 'kelontong';
    case DepotAir = 'depot_air';
    case Laundry = 'laundry';
    case Campuran = 'campuran';

    public function label(): string
    {
        return match ($this) {
            self::Fnb => 'FnB / Rumah Makan',
            self::Kelontong => 'Toko Kelontong',
            self::DepotAir => 'Depot Air Isi Ulang',
            self::Laundry => 'Laundry',
            self::Campuran => 'Campuran',
        };
    }

    /** Modul resep/BOM & role Dapur hanya relevan untuk usaha yang memasak. */
    public function supportsRecipe(): bool
    {
        return in_array($this, [self::Fnb, self::Campuran], true);
    }

    /**
     * Apakah usaha ini menjual barang berbarcode pabrik.
     *
     * Kelontong menjual barang kemasan yang sudah punya barcode — mendaftarkannya
     * dengan mengetik nama satu per satu bisa memakan berjam-jam, sedangkan memindai
     * hanya butuh sedetik per barang. Rumah makan sebaliknya: nasi dan ayam goreng
     * tidak punya barcode, jadi kolomnya hanya menambah medan kosong yang harus
     * dilewati setiap kali menambah menu.
     */
    public function pakaiBarcode(): bool
    {
        return in_array($this, [self::Kelontong, self::Campuran], true);
    }

    /** Mode transaksi yang wajar diaktifkan saat setup outlet pertama. */
    public function defaultModes(): array
    {
        return match ($this) {
            self::Fnb => [TransactionMode::OpenBill, TransactionMode::Langsung],
            self::Kelontong => [TransactionMode::Langsung],
            self::DepotAir, self::Laundry => [TransactionMode::PesanAntar, TransactionMode::Langsung],
            self::Campuran => [TransactionMode::Langsung, TransactionMode::OpenBill, TransactionMode::PesanAntar],
        };
    }
}
