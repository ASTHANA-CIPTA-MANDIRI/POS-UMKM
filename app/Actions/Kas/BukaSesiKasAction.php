<?php

namespace App\Actions\Kas;

use App\Enums\CashSessionStatus;
use App\Models\Kas\CashSession;
use App\Models\Tenant\User;
use RuntimeException;

/**
 * Membuka sesi kas (laci kas) untuk satu kasir di satu outlet.
 *
 * Satu kasir hanya boleh punya SATU sesi terbuka. Kalau dua sesi bisa berjalan
 * bersamaan, uang tunai dari transaksi yang sama bisa masuk ke laci yang berbeda
 * dan rekonsiliasi akhir shift tidak akan pernah cocok.
 */
class BukaSesiKasAction
{
    public function execute(User $kasir, float $modalAwal): CashSession
    {
        if ($kasir->outlet_id === null) {
            throw new RuntimeException('Akun ini belum ditugaskan ke outlet mana pun.');
        }

        if ($this->sesiBerjalan($kasir) !== null) {
            throw new RuntimeException('Masih ada sesi kas yang terbuka. Tutup dulu sebelum membuka yang baru.');
        }

        if ($modalAwal < 0) {
            throw new RuntimeException('Modal awal tidak boleh negatif.');
        }

        $sesi = new CashSession([
            'outlet_id' => $kasir->outlet_id,
            'staff_id' => $kasir->getKey(),
            'dibuka_pada' => now(),
            'modal_awal' => $modalAwal,
            'status' => CashSessionStatus::Terbuka,
        ]);

        $sesi->tenant_id = $kasir->tenant_id;
        $sesi->save();

        return $sesi;
    }

    public function sesiBerjalan(User $kasir): ?CashSession
    {
        return CashSession::where('staff_id', $kasir->getKey())
            ->where('outlet_id', $kasir->outlet_id)
            ->where('status', CashSessionStatus::Terbuka)
            ->latest('dibuka_pada')
            ->first();
    }
}
