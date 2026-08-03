<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'transaction_id', 'product_id', 'product_variant_id', 'nama_produk',
    'qty', 'harga_satuan', 'diskon', 'subtotal', 'catatan_modifier',
])]
class TransactionItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'harga_satuan' => 'decimal:2',
            'diskon' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
