<?php

namespace App\Models\Kasir;

use App\Enums\BillStatus;
use App\Enums\TransactionMode;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Pelanggan\Customer;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tagihan berjalan untuk Mode B (open bill) & Mode C (pesan/titip).
 * Mode A (langsung) tidak memakai bill.
 */
/**
 * Catatan offline: sama seperti Transaction, id bill boleh dibuat di perangkat saat
 * kasir membuka bill tanpa jaringan — karena itu 'id' ikut fillable.
 */
#[Fillable([
    'id', 'outlet_id', 'mode', 'status', 'label', 'customer_id',
    'dibuka_oleh', 'dibuka_pada', 'ditutup_pada', 'estimasi_selesai', 'catatan',
])]
class Bill extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /** Disamakan dengan default di migrasi. */
    protected $attributes = [
        'status' => 'terbuka',
    ];

    protected function casts(): array
    {
        return [
            'mode' => TransactionMode::class,
            'status' => BillStatus::class,
            'dibuka_pada' => 'datetime',
            'ditutup_pada' => 'datetime',
            'estimasi_selesai' => 'datetime',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function dibukaOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuka_oleh');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Daftar bill terbuka per outlet — query terpanas di UI kasir. */
    public function scopeTerbuka(Builder $query): Builder
    {
        return $query->whereNot('status', BillStatus::SelesaiDibayar);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
