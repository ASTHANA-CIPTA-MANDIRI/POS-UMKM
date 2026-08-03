<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stok dicatat per outlet. Satu baris mengacu ke product_id ATAU raw_material_id
 * (tepat salah satu).
 */
#[Fillable([
    'outlet_id', 'product_id', 'raw_material_id',
    'jumlah_saat_ini', 'stok_minimum', 'tanggal_kadaluarsa',
])]
class Stock extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return [
            'jumlah_saat_ini' => 'decimal:3',
            'stok_minimum' => 'decimal:3',
            'tanggal_kadaluarsa' => 'date',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** Nama item apa pun jenisnya, untuk ditampilkan di kartu stok. */
    public function namaItem(): string
    {
        return $this->product?->nama_produk ?? $this->rawMaterial?->nama ?? '-';
    }

    /** Alert stok minimum. */
    public function isLow(): bool
    {
        return (float) $this->jumlah_saat_ini <= (float) $this->stok_minimum;
    }

    /** Alert produk mendekati kadaluarsa. */
    public function isExpiringSoon(int $hari = 30): bool
    {
        return $this->tanggal_kadaluarsa !== null
            && $this->tanggal_kadaluarsa->isBefore(now()->addDays($hari));
    }
}
