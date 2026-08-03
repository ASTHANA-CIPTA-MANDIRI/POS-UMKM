<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_transfer_id', 'product_id', 'raw_material_id', 'qty', 'qty_diterima'])]
class StockTransferItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'qty_diterima' => 'decimal:3',
        ];
    }

    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class);
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
