<?php

namespace App\Models\Langganan;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Kasir\Payment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'subscription_id', 'nomor_invoice', 'periode_mulai', 'periode_selesai',
    'jumlah_tagihan', 'status', 'jatuh_tempo', 'dibayar_pada',
])]
class Invoice extends Model
{
    use BelongsToTenant, HasUuids;

    /** Disamakan dengan default di migrasi. */
    protected $attributes = [
        'status' => 'belum_bayar',
    ];

    protected function casts(): array
    {
        return [
            'periode_mulai' => 'date',
            'periode_selesai' => 'date',
            'jatuh_tempo' => 'date',
            'dibayar_pada' => 'datetime',
            'jumlah_tagihan' => 'decimal:2',
            'status' => InvoiceStatus::class,
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isOverdue(): bool
    {
        return $this->status->isOutstanding() && $this->jatuh_tempo->isPast();
    }

    public function totalDibayar(): string
    {
        return (string) $this->payments()->sum('jumlah');
    }
}
