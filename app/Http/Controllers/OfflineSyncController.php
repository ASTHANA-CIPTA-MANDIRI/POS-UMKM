<?php

namespace App\Http\Controllers;

use App\Actions\Sync\SyncOfflineTransactionsAction;
use App\Http\Requests\SyncOfflineTransactionsRequest;
use Illuminate\Http\JsonResponse;

class OfflineSyncController extends Controller
{
    /**
     * Menerima antrean transaksi offline dari perangkat kasir.
     *
     * Boleh dipanggil ulang dengan payload yang sama: transaksi yang sudah ada
     * dilaporkan sebagai duplikat, bukan dibuat dua kali. Karena itu klien tidak
     * perlu memastikan request sebelumnya berhasil — cukup kirim ulang antreannya
     * sampai server membalas, lalu hapus yang sudah dikonfirmasi.
     */
    public function store(
        SyncOfflineTransactionsRequest $request,
        SyncOfflineTransactionsAction $sync,
    ): JsonResponse {
        $result = $sync->execute($request->user(), $request->validated());

        return response()->json(
            $result->toArray(),
            $result->semuaBerhasil() ? 200 : 207,
        );
    }
}
