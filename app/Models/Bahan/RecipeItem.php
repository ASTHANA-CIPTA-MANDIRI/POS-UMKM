<?php

namespace App\Models\Bahan;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Produk\Product;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris resep/BOM: berapa banyak 1 bahan baku dipakai per 1 porsi menu.
 */
#[Fillable(['product_id', 'raw_material_id', 'jumlah_terpakai'])]
class RecipeItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return [
            'jumlah_terpakai' => 'decimal:3',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    /** Kebutuhan bahan baku untuk sejumlah porsi terjual. */
    public function kebutuhanUntuk(float $porsi): float
    {
        return (float) $this->jumlah_terpakai * $porsi;
    }
}
