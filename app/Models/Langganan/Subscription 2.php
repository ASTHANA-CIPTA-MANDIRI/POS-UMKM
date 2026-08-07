<?php

namespace App\Models\Langganan;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'plan_id', 'device_bundle', 'tanggal_mulai', 'tanggal_berakhir',
    'status', 'grace_period_sampai',
])]
class Subscription extends Model
{
    use BelongsToTenant, HasUuids;

    /** Disamakan dengan default di migrasi. */
    protected $attributes = [
        'device_bundle' => false,
        'status' => 'aktif',
    ];

    protected function casts(): array
    {
        return [
            'device_bundle' => 'boolean',
            'tanggal_mulai' => 'date',
            'tanggal_berakhir' => 'date',
            'grace_period_sampai' => 'date',
            'status' => SubscriptionStatus::class,
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Paket +device: platform meminjamkan perangkat & merchant bayar deposit. */
    public function includesDevice(): bool
    {
        return $this->device_bundle;
    }

    public function isExpired(): bool
    {
        return $this->tanggal_berakhir !== null && $this->tanggal_berakhir->isPast();
    }
}
