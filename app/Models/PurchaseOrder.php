<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'outlet_id', 'supplier_id', 'nomor_po', 'tanggal', 'total',
    'diskon', 'ongkos_kirim',
    'status', 'catatan', 'dibuat_oleh', 'diterima_pada',
])]
class PurchaseOrder extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /** Disamakan dengan default di migrasi. */
    protected $attributes = [
        'total' => 0,
        'diskon' => 0,
        'ongkos_kirim' => 0,
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'total' => 'decimal:2',
            'diskon' => 'decimal:2',
            'ongkos_kirim' => 'decimal:2',
            'status' => DocumentStatus::class,
            'diterima_pada' => 'datetime',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    /** Mutasi stok yang dihasilkan saat PO diterima. */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'referensi');
    }

    public function hitungTotal(): string
    {
        return (string) $this->items()->sum('subtotal');
    }
}
