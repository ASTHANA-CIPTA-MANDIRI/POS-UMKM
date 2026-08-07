<?php

namespace App\Models\Tenant;

use App\Enums\DeviceOwnership;
use App\Enums\DeviceStatus;
use App\Enums\DeviceType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Kasir\Transaction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'outlet_id', 'device_type', 'serial_number', 'ownership', 'status',
    'deposit_amount', 'mendukung_pelacakan', 'is_perangkat_utama',
])]
class Device extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /**
     * Disamakan dengan default di migrasi. Tanpa ini, instance hasil create()
     * punya atribut null sampai di-refresh dari database, sehingga helper yang
     * membaca enum (mis. canBeUsedForLogin) gagal.
     */
    protected $attributes = [
        'ownership' => 'milik_merchant',
        'status' => 'aktif',
        'mendukung_pelacakan' => false,
        'is_perangkat_utama' => true,
    ];

    protected function casts(): array
    {
        return [
            'device_type' => DeviceType::class,
            'ownership' => DeviceOwnership::class,
            'status' => DeviceStatus::class,
            'deposit_amount' => 'decimal:2',
            'last_known_lat' => 'decimal:7',
            'last_known_lng' => 'decimal:7',
            'last_seen_at' => 'datetime',
            'mendukung_pelacakan' => 'boolean',
            'is_perangkat_utama' => 'boolean',
            'mdm_locked_at' => 'datetime',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function assetLogs(): HasMany
    {
        return $this->hasMany(DeviceAssetLog::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** User yang login-nya di-bind ke perangkat ini. */
    public function boundUsers(): HasMany
    {
        return $this->hasMany(User::class, 'device_id_terikat');
    }

    /**
     * Printer thermal tidak punya GPS/koneksi sendiri, dan EDC hanya bisa dilacak
     * kalau punya SIM sendiri — jadi tipe DAN flag per unit harus sama-sama benar.
     */
    public function isTrackable(): bool
    {
        return $this->device_type->canSupportTracking() && $this->mendukung_pelacakan;
    }

    public function isLocked(): bool
    {
        return $this->mdm_locked_at !== null;
    }

    public function requiresDeposit(): bool
    {
        return $this->ownership->requiresDeposit();
    }

    public function canBeUsedForLogin(): bool
    {
        return $this->status->canBeUsedForLogin() && ! $this->isLocked();
    }
}
