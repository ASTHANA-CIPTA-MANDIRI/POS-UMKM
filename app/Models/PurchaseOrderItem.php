<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'purchase_order_id', 'product_id', 'raw_material_id',
    'qty', 'qty_diterima', 'harga_satuan', 'subtotal',
])]
class PurchaseOrderItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'qty_diterima' => 'decimal:3',
            'harga_satuan' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function namaItem(): string
    {
        return $this->product?->nama_produk ?? $this->rawMaterial?->nama ?? '-';
    }
}
