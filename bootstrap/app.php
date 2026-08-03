<?php

use App\Http\Middleware\PastikanPeran;
use App\Http\Middleware\PastikanTenantAktif;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Harus berjalan sesudah session/auth supaya $request->user() sudah terisi.
        $middleware->web(append: [
            ResolveTenantContext::class,
        ]);

        $middleware->alias([
            'peran' => PastikanPeran::class,
            'tenant-aktif' => PastikanTenantAktif::class,
        ]);

        // Pengunjung yang belum login diarahkan ke halaman masuk pemilik. Kasir
        // punya halaman sendiri dan tautannya tersedia di halaman itu.
        $middleware->redirectGuestsTo(fn () => route('masuk'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Scaffold bawaan hanya merender JSON untuk path api/*. Endpoint sinkronisasi
        // offline berada di grup web (butuh sesi login), jadi tanpa expectsJson()
        // kegagalan validasi dibalas redirect HTML 302 — dan klien kasir tidak punya
        // cara membaca error dari situ.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
