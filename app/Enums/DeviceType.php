<?php

namespace App\Enums;

enum DeviceType: string
{
    case Tablet = 'tablet';
    case PrinterThermal = 'printer_thermal';
    case Edc = 'edc';

    public function label(): string
    {
        return match ($this) {
            self::Tablet => 'Tablet',
            self::PrinterThermal => 'Printer Thermal',
            self::Edc => 'EDC',
        };
    }

    /**
     * Printer thermal TIDAK bisa dilacak — perangkat pasif tanpa GPS/koneksi
     * sendiri, jadi deposit adalah satu-satunya proteksi. EDC hanya bisa dilacak
     * kalau punya SIM/jaringan seluler sendiri, sehingga diputuskan per unit lewat
     * kolom devices.mendukung_pelacakan, bukan dari tipe saja.
     */
    public function canSupportTracking(): bool
    {
        return $this !== self::PrinterThermal;
    }
}
