<?php

namespace App\Models\Tenant;

use App\Enums\BusinessType;
use App\Enums\TenantStatus;
use App\Models\Bahan\RawMaterial;
use App\Models\Kasir\Transaction;
use App\Models\Langganan\Invoice;
use App\Models\Langganan\Subscription;
use App\Models\Pelanggan\Customer;
use App\Models\Pembelian\Supplier;
use App\Models\Produk\Category;
use App\Models\Produk\Product;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Entitas paling atas — 1 akun merchant yang bisa punya banyak outlet.
 * Tidak memakai trait BelongsToTenant karena tabel ini adalah tenant itu sendiri.
 */
#[Fillable(['business_name', 'business_type', 'owner_name', 'owner_phone', 'status', 'trial_ends_at'])]
class Tenant extends Model
{
    use HasUuids, SoftDeletes;

    /** Disamakan dengan default di migrasi. */
    protected $attributes = [
        'status' => 'trial',
    ];

    protected function casts(): array
    {
        return [
            'business_type' => BusinessType::class,
            'status' => TenantStatus::class,
            'trial_ends_at' => 'datetime',
        ];
    }

    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function rawMaterials(): HasMany
    {
        return $this->hasMany(RawMaterial::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Langganan terakhir yang masih berjalan. */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function canTransact(): bool
    {
        return $this->status->canTransact();
    }
}
