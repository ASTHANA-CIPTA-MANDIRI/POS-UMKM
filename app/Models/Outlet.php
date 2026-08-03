<?php

namespace App\Models;

use App\Enums\StockModel;
use App\Enums\TransactionMode;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['outlet_name', 'address', 'active_modes', 'stock_model', 'is_active'])]
class Outlet extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /** Disamakan dengan default di migrasi. */
    protected $attributes = [
        'stock_model' => 'mandiri',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'active_modes' => 'array',
            'stock_model' => StockModel::class,
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function cashSessions(): HasMany
    {
        return $this->hasMany(CashSession::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function transfersKeluar(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'outlet_asal_id');
    }

    public function transfersMasuk(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'outlet_tujuan_id');
    }

    /** @return array<int, TransactionMode> */
    public function modes(): array
    {
        return array_values(array_filter(array_map(
            fn (string $mode) => TransactionMode::tryFrom($mode),
            $this->active_modes ?? [],
        )));
    }

    /** Kasir hanya boleh memilih mode yang diaktifkan di outlet ini. */
    public function supportsMode(TransactionMode $mode): bool
    {
        return in_array($mode->value, $this->active_modes ?? [], true);
    }

    /** Perangkat cadangan yang sudah ter-binding, dipakai kalau utama rusak. */
    public function perangkatCadangan(): HasMany
    {
        return $this->devices()->where('is_perangkat_utama', false);
    }
}
