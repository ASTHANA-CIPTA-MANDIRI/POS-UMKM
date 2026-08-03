<?php

namespace App\Enums;

/**
 * Role sengaja dibatasi dan tetap (bukan role bebas/dinamis) sesuai bagian 3.2.E
 * dokumen fitur: FnB hanya butuh Kasir + Dapur, usaha lain cukup Kasir.
 */
enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Owner = 'owner';
    case RegionalManager = 'regional_manager';
    case ManagerOutlet = 'manager_outlet';
    case Kasir = 'kasir';
    case Dapur = 'dapur';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Owner => 'Owner',
            self::RegionalManager => 'Area/Regional Manager',
            self::ManagerOutlet => 'Manager Outlet',
            self::Kasir => 'Kasir',
            self::Dapur => 'Dapur',
        };
    }

    /** Role yang berada di level platform, bukan milik tenant mana pun. */
    public function isPlatformLevel(): bool
    {
        return $this === self::SuperAdmin;
    }

    /** Role yang wajib dikunci ke tepat 1 outlet (hard block saat login). */
    public function requiresOutlet(): bool
    {
        return in_array($this, [self::ManagerOutlet, self::Kasir, self::Dapur], true);
    }

    /** Owner & Regional Manager boleh mengakses lebih dari satu outlet. */
    public function isMultiOutlet(): bool
    {
        return in_array($this, [self::Owner, self::RegionalManager], true);
    }

    /** Staff tidak boleh melihat laporan keuangan/laba. */
    public function canSeeFinancials(): bool
    {
        return in_array($this, [self::SuperAdmin, self::Owner, self::RegionalManager, self::ManagerOutlet], true);
    }
}
