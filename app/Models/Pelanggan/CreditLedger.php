<?php

namespace App\Models\Pelanggan;

use App\Enums\CreditStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Kasir\Transaction;
use App\Models\Tenant\Outlet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kasbon/utang pelanggan. Dicatat sebagai "belum lunas" setelah struk tercetak,
 * terpisah dari proses bayar transaksi itu sendiri.
 */
#[Fillable([
    'outlet_id', 'customer_id', 'transaction_id', 'jumlah_utang', 'jumlah_dibayar',
    'status', 'tanggal_jatuh_tempo', 'dilunasi_pada', 'catatan',
])]
class CreditLedger extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /** Disamakan dengan default di migrasi. */
    protected $attributes = [
        'jumlah_dibayar' => 0,
        'status' => 'belum_lunas',
    ];

    protected function casts(): array
    {
        return [
            'jumlah_utang' => 'decimal:2',
            'jumlah_dibayar' => 'decimal:2',
            'status' => CreditStatus::class,
            'tanggal_jatuh_tempo' => 'date',
            'dilunasi_pada' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * Riwayat setoran. Yang dibatalkan tidak ikut (SoftDeletingScope pada CreditPayment).
     *
     * `jumlah_dibayar` di baris ini adalah SUM dari relasi ini, dihitung ulang oleh
     * CatatPelunasanAction. Kalau keduanya pernah berbeda, yang benar adalah tabel setoran —
     * ia punya tanggal, penerima, dan jejak pembatalan; kolom agregatnya tidak punya apa-apa.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(CreditPayment::class);
    }

    public function sisaUtang(): float
    {
        return (float) $this->jumlah_utang - (float) $this->jumlah_dibayar;
    }

    public function isOverdue(): bool
    {
        return $this->status === CreditStatus::BelumLunas
            && $this->tanggal_jatuh_tempo !== null
            && $this->tanggal_jatuh_tempo->isPast();
    }
}
