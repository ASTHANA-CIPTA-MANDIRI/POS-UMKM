<?php

namespace App\Models\Stok;

use App\Enums\DocumentStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'outlet_asal_id', 'outlet_tujuan_id', 'nomor_transfer', 'tanggal',
    'status', 'catatan', 'dikirim_oleh', 'diterima_oleh', 'diterima_pada',
])]
class StockTransfer extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /** Disamakan dengan default di migrasi. */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'status' => DocumentStatus::class,
            'diterima_pada' => 'datetime',
        ];
    }

    public function outletAsal(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_asal_id');
    }

    public function outletTujuan(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_tujuan_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function dikirimOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dikirim_oleh');
    }

    public function diterimaOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diterima_oleh');
    }

    /** Satu transfer menghasilkan dua sisi mutasi: keluar di asal, masuk di tujuan. */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'referensi');
    }
}
