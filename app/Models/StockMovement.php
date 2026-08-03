<?php

namespace App\Models;

use App\Enums\AlasanOpname;
use App\Enums\StockMovementType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Kartu stok. Referensi bersifat polymorphic: PurchaseOrder, StockTransfer,
 * Transaction, atau NULL untuk stok opname.
 */
#[Fillable([
    'outlet_id', 'stock_id', 'tipe', 'alasan', 'jumlah', 'saldo_sesudah',
    'referensi_type', 'referensi_id', 'oleh_user_id', 'catatan',
])]
class StockMovement extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return [
            'tipe' => StockMovementType::class,
            // Kode alasan, bukan teks bebas: nilainya harus tetap bisa dikelompokkan
            // di laporan selisih. Nullable — penjualan/pembelian/transfer tidak punya
            // alasan, tipenya sudah menjelaskan sebabnya.
            'alasan' => AlasanOpname::class,
            'jumlah' => 'decimal:3',
            'saldo_sesudah' => 'decimal:3',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function referensi(): MorphTo
    {
        return $this->morphTo();
    }

    public function olehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'oleh_user_id');
    }
}
