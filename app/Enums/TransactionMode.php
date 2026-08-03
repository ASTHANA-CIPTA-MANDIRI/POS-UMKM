<?php

namespace App\Enums;

/**
 * Mode diaktifkan per outlet, lalu dipilih kasir PER-TRANSAKSI — tidak dikunci
 * satu mode untuk seluruh outlet, supaya usaha campuran tetap bisa pakai satu
 * sistem yang sama (bagian 3.2.A).
 */
enum TransactionMode: string
{
    case Langsung = 'langsung';
    case OpenBill = 'open_bill';
    case PesanAntar = 'pesan_antar';

    public function label(): string
    {
        return match ($this) {
            self::Langsung => 'Transaksi Langsung',
            self::OpenBill => 'Buka Bill / Bayar di Akhir',
            self::PesanAntar => 'Pesan-Antar & Titip-Ambil',
        };
    }

    /**
     * Mode A membuat transaksi langsung tanpa bill; Mode B & C wajib lewat bill
     * dulu, baru ditutup jadi transaksi saat bayar.
     */
    public function requiresBill(): bool
    {
        return $this !== self::Langsung;
    }
}
