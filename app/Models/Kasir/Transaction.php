<?php

namespace App\Models\Kasir;

use App\Enums\TransactionMode;
use App\Enums\TransactionOrigin;
use App\Enums\TransactionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Kas\CashMovement;
use App\Models\Pelanggan\CreditLedger;
use App\Models\Pelanggan\Customer;
use App\Models\Stok\StockMovement;
use App\Models\Tenant\Device;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catatan offline: id adalah UUID yang boleh dibuat di perangkat saat transaksi
 * terjadi tanpa koneksi, sehingga menjadi kunci idempotensi saat sinkronisasi.
 * Kolom disinkronkan_pada sengaja TIDAK fillable — nilainya ditetapkan server,
 * bukan dikirim perangkat.
 */
#[Fillable([
    'id', 'outlet_id', 'bill_id', 'customer_id', 'staff_id', 'device_id',
    'nomor_transaksi', 'mode', 'subtotal', 'diskon', 'pajak', 'service_charge',
    'total', 'status', 'waktu_transaksi', 'origin', 'dibuat_offline_pada',
])]
class Transaction extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /** Disamakan dengan default di migrasi. */
    protected $attributes = [
        'subtotal' => 0,
        'diskon' => 0,
        'pajak' => 0,
        'service_charge' => 0,
        'total' => 0,
        'status' => 'lunas',
        'origin' => 'online',
    ];

    protected function casts(): array
    {
        return [
            'mode' => TransactionMode::class,
            'status' => TransactionStatus::class,
            'subtotal' => 'decimal:2',
            'diskon' => 'decimal:2',
            'pajak' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'total' => 'decimal:2',
            'waktu_transaksi' => 'datetime',
            'di_void_pada' => 'datetime',
            'origin' => TransactionOrigin::class,
            'dibuat_offline_pada' => 'datetime',
            'disinkronkan_pada' => 'datetime',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function diVoidOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'di_void_oleh');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TransactionPayment::class);
    }

    public function creditLedger(): HasOne
    {
        return $this->hasOne(CreditLedger::class);
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    /** Stok keluar yang dipicu transaksi ini. */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'referensi');
    }

    /** Hanya transaksi ini yang dihitung sebagai omzet. */
    public function scopeOmzet(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TransactionStatus::Lunas,
            TransactionStatus::BelumLunas,
        ]);
    }

    public function totalDibayar(): float
    {
        return (float) $this->payments()->sum('jumlah');
    }

    /** Sisa yang belum dibayar — jadi dasar pembuatan kasbon. */
    public function sisaTagihan(): float
    {
        return (float) $this->total - $this->totalDibayar();
    }

    public function isVoid(): bool
    {
        return $this->status === TransactionStatus::Void;
    }
}
