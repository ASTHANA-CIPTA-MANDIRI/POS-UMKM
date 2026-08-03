<?php

namespace App\Actions\Stock;

use App\Enums\StockMovementType;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya jalur mengubah jumlah stok. Selalu meninggalkan jejak di
 * stock_movements supaya kartu stok bisa direkonstruksi.
 *
 * Kolom stock_movements.jumlah disimpan BERTANDA (negatif untuk pengurangan),
 * sedangkan kolom tipe menjelaskan alasannya. Dengan begitu saldo bisa dihitung
 * lewat SUM(jumlah) tanpa perlu tahu semantik tiap tipe.
 */
class AdjustStockAction
{
    public function execute(
        Stock $stock,
        StockMovementType $tipe,
        float $delta,
        ?Model $referensi = null,
        ?string $olehUserId = null,
        ?string $catatan = null,
    ): StockMovement {
        return DB::transaction(function () use ($stock, $tipe, $delta, $referensi, $olehUserId, $catatan) {
            // Lock baris supaya dua transaksi bersamaan tidak saling menimpa saldo.
            $terkunci = Stock::query()->whereKey($stock->getKey())->lockForUpdate()->first() ?? $stock;

            $saldoBaru = (float) $terkunci->jumlah_saat_ini + $delta;

            $terkunci->jumlah_saat_ini = $saldoBaru;
            $terkunci->save();

            $movement = new StockMovement([
                'outlet_id' => $terkunci->outlet_id,
                'stock_id' => $terkunci->getKey(),
                'tipe' => $tipe,
                'jumlah' => $delta,
                'saldo_sesudah' => $saldoBaru,
                'oleh_user_id' => $olehUserId,
                'catatan' => $catatan,
            ]);

            $movement->tenant_id = $terkunci->tenant_id;

            if ($referensi !== null) {
                $movement->referensi()->associate($referensi);
            }

            $movement->save();

            return $movement;
        });
    }
}
