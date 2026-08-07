<?php

namespace App\Actions\Stock;

use App\Enums\AlasanOpname;
use App\Enums\StockMovementType;
use App\Models\Stok\Stock;
use App\Models\Stok\StockMovement;
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
    /**
     * @param  ?AlasanOpname  $alasan  Hanya untuk mutasi opname/penyesuaian manual.
     *                                 Penjualan, pembelian, dan transfer TIDAK punya alasan —
     *                                 tipenya sendiri sudah menjelaskan sebabnya, dan mengisi
     *                                 kolom ini untuk mereka membuat laporan selisih penuh
     *                                 baris yang bukan selisih.
     */
    public function execute(
        Stock $stock,
        StockMovementType $tipe,
        float $delta,
        ?Model $referensi = null,
        ?string $olehUserId = null,
        ?string $catatan = null,
        ?AlasanOpname $alasan = null,
    ): StockMovement {
        return DB::transaction(function () use ($stock, $tipe, $delta, $referensi, $olehUserId, $catatan, $alasan) {
            // Lock baris supaya dua transaksi bersamaan tidak saling menimpa saldo.
            $terkunci = Stock::query()->whereKey($stock->getKey())->lockForUpdate()->first() ?? $stock;

            $saldoBaru = (float) $terkunci->jumlah_saat_ini + $delta;

            $terkunci->jumlah_saat_ini = $saldoBaru;
            $terkunci->save();

            $movement = new StockMovement([
                'outlet_id' => $terkunci->outlet_id,
                'stock_id' => $terkunci->getKey(),
                'tipe' => $tipe,
                'alasan' => $alasan,
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
