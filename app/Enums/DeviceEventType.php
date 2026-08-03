<?php

namespace App\Enums;

enum DeviceEventType: string
{
    case Registrasi = 'registrasi';
    case Reset = 'reset';
    case KlaimRusak = 'klaim_rusak';
    case Hilang = 'hilang';
    case LockMdm = 'lock_mdm';
    case UnlockMdm = 'unlock_mdm';
    case Ditarik = 'ditarik';

    public function label(): string
    {
        return match ($this) {
            self::Registrasi => 'Registrasi Perangkat',
            self::Reset => 'Reset/Ganti Perangkat Outlet',
            self::KlaimRusak => 'Klaim Kerusakan',
            self::Hilang => 'Laporan Kehilangan',
            self::LockMdm => 'Remote Lock via MDM',
            self::UnlockMdm => 'Remote Unlock via MDM',
            self::Ditarik => 'Penarikan Perangkat',
        };
    }
}
