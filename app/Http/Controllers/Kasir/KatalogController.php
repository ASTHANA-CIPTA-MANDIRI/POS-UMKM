<?php

namespace App\Http\Controllers\Kasir;

use App\Actions\Kasir\SusunKatalogAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Katalog produk untuk layar kasir, dalam bentuk JSON.
 *
 * Katalog awal sudah ditanam di halaman saat dimuat; endpoint ini dipakai untuk
 * MENYEGARKAN katalog di perangkat tanpa memuat ulang seluruh halaman — misalnya
 * setelah owner menambah menu baru di tengah shift.
 *
 * Outlet TIDAK diambil dari parameter request — selalu dari record kasir yang login.
 * Kalau outlet boleh dikirim dari layar, kasir Outlet A bisa membaca katalog dan
 * harga Outlet B hanya dengan mengubah satu nilai di URL.
 */
class KatalogController extends Controller
{
    public function __invoke(Request $request, SusunKatalogAction $susun): JsonResponse
    {
        $user = $request->user();

        if ($user->outlet_id === null) {
            return response()->json([
                'pesan' => 'Akun ini belum ditugaskan ke outlet mana pun.',
            ], 409);
        }

        return response()->json([
            // Dipakai klien untuk memutuskan apakah cache lokalnya sudah usang.
            'versi' => now()->timestamp,
            'outlet_id' => $user->outlet_id,
            'outlet' => $user->outlet?->outlet_name,
            'device_id' => $user->device_id_terikat,
            ...$susun->execute($user),
        ]);
    }
}
