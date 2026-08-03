<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menghalangi aksi transaksional ketika langganan merchant tidak lagi berjalan
 * (trial habis, suspend, atau nonaktif).
 *
 * Yang dihalangi HANYA jalur yang memakai middleware ini — halaman tagihan dan
 * pengaturan langganan sengaja dibiarkan terbuka. Alur 2.3 dokumen bisnis: saat
 * suspend, merchant tidak bisa transaksi tapi datanya tetap tersimpan dan ia harus
 * tetap bisa masuk untuk membayar. Memblokir seluruh aplikasi justru menutup jalan
 * merchant membayar tagihannya.
 *
 * Super Admin dilewati karena tidak terikat tenant mana pun.
 */
class PastikanTenantAktif
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->isPlatformLevel()) {
            return $next($request);
        }

        $tenant = $user->tenant;

        if ($tenant !== null && ! $tenant->canTransact()) {
            return redirect()
                ->route('owner.langganan')
                ->with('peringatan', 'Langganan sedang tidak aktif, jadi transaksi dihentikan sementara. Data Anda tetap tersimpan.');
        }

        return $next($request);
    }
}
