<?php

namespace App\Models\Kasir;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris per metode bayar — split payment (cash + QRIS) menghasilkan
 * beberapa baris untuk satu transaksi.
 */
#[Fillable([
    'transaction_id', 'metode', 'jumlah',
    'jumlah_diterima', 'kembalian', 'referensi',
])]
class TransactionPayment extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return [
            'metode' => PaymentMethod::class,
            'jumlah' => 'decimal:2',
            'jumlah_diterima' => 'decimal:2',
            'kembalian' => 'decimal:2',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function affectsCashDrawer(): bool
    {
        return $this->metode->affectsCashDrawer();
    }
}
