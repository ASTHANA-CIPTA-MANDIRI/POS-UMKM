<?php

namespace App\Models\Kasir;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Langganan\Invoice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pembayaran tagihan langganan platform — bukan pembayaran transaksi kasir
 * (itu ada di TransactionPayment).
 */
#[Fillable([
    'invoice_id', 'metode', 'jumlah', 'tanggal_bayar',
    'referensi_gateway', 'bukti_transfer_path',
])]
class Payment extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return [
            'jumlah' => 'decimal:2',
            'tanggal_bayar' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
