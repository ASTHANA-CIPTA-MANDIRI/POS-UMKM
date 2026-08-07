<?php

namespace App\Models\Sistem;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log aksi sensitif: void transaksi, reset device, perubahan harga, impersonate.
 * tenant_id NULL berarti aksi Super Admin di level platform.
 */
#[Fillable([
    'outlet_id', 'user_id', 'aksi', 'entitas_terkait', 'entitas_id',
    'detail_json', 'ip_address',
])]
class AuditLog extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return [
            'detail_json' => 'array',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
