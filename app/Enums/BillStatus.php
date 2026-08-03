<?php

namespace App\Enums;

enum BillStatus: string
{
    case Terbuka = 'terbuka';
    case Diproses = 'diproses';
    case SiapDiambil = 'siap_diambil';
    case SelesaiDibayar = 'selesai_dibayar';

    public function label(): string
    {
        return match ($this) {
            self::Terbuka => 'Terbuka',
            self::Diproses => 'Diproses',
            self::SiapDiambil => 'Siap Diambil/Diantar',
            self::SelesaiDibayar => 'Selesai & Dibayar',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::SelesaiDibayar;
    }

    /**
     * Urutan maju alur Mode C: diterima → diproses → siap diambil → selesai dibayar.
     *
     * Dipakai untuk menolak status yang MEMUNDURKAN. Antrean offline bisa tiba tidak
     * berurutan — perangkat mungkin mengirim "diproses" setelah "siap diambil" karena
     * paket pertama sempat gagal. Tanpa pembanding urutan, status bill bisa mundur
     * sendiri dan pelanggan diberi tahu cuciannya belum selesai padahal sudah.
     */
    public function urutan(): int
    {
        return match ($this) {
            self::Terbuka => 0,
            self::Diproses => 1,
            self::SiapDiambil => 2,
            self::SelesaiDibayar => 3,
        };
    }
}
