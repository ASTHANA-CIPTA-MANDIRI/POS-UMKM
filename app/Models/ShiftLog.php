<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'outlet_id', 'user_id', 'jadwal_mulai', 'jadwal_selesai',
    'mulai_aktual', 'selesai_aktual', 'catatan',
])]
class ShiftLog extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return [
            'jadwal_mulai' => 'datetime',
            'jadwal_selesai' => 'datetime',
            'mulai_aktual' => 'datetime',
            'selesai_aktual' => 'datetime',
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
