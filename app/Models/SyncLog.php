<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'outlet_id', 'device_id', 'user_id', 'jumlah_dikirim',
    'jumlah_dibuat', 'jumlah_duplikat', 'jumlah_gagal', 'detail_gagal',
])]
class SyncLog extends Model
{
    use BelongsToTenant, HasUuids;

    /** Disamakan dengan default di migrasi. */
    protected $attributes = [
        'jumlah_dikirim' => 0,
        'jumlah_dibuat' => 0,
        'jumlah_duplikat' => 0,
        'jumlah_gagal' => 0,
    ];

    protected function casts(): array
    {
        return [
            'detail_gagal' => 'array',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
