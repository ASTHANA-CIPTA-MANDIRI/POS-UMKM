<?php

namespace App\Models\Bahan;

use App\Enums\Satuan;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Produk\Product;
use App\Models\Stok\Stock;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['nama', 'satuan', 'harga_beli_terakhir', 'is_active'])]
class RawMaterial extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /** Disamakan dengan default di migrasi. */
    protected $attributes = [
        'satuan' => 'kg',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'satuan' => Satuan::class,
            'harga_beli_terakhir' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    /** Menu jadi yang memakai bahan baku ini. */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'recipe_items')
            ->withPivot('jumlah_terpakai')
            ->withTimestamps();
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }
}
