<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang peran. Dipakai sebagai `peran:owner,manager_outlet` di definisi route.
 *
 * Peran dibaca dari record user di database, bukan dari sesi yang bisa dimanipulasi
 * maupun dari parameter request. Kalau perannya tidak diizinkan, user dialihkan ke
 * beranda miliknya sendiri — bukan diberi 403 telanjang — supaya kasir yang salah
 * membuka URL back office tidak terjebak di halaman mati.
 */
class PastikanPeran
{
    public function handle(Request $request, Closure $next, string ...$peranDiizinkan): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('masuk');
        }

        $diizinkan = array_filter(array_map(
            fn (string $nama) => UserRole::tryFrom($nama),
            $peranDiizinkan,
        ));

        if (! in_array($user->role, $diizinkan, true)) {
            return redirect()->route($user->rutaBeranda());
        }

        return $next($request);
    }
}
