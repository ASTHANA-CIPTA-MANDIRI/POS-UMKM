<?php

namespace App\Models\Pembelian;

use App\Models\Bahan\RawMaterial;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Produk\Product;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris nota pembelian.
 *
 * Tiga kolom menyimpan jumlah, dan bedanya penting:
 * - `qty`      jumlah dalam satuan DASAR — inilah yang masuk kartu stok (2 dus isi 12 = 24)
 * - `qty_beli` jumlah dalam satuan BELI — angka yang benar-benar diketik pemilik (2)
 * - `qty_diterima` sama dengan `qty`; nota disimpan berarti barangnya sudah datang.
 *
 * `harga_satuan` adalah harga per satuan BELI (58.000 per dus), bukan per satuan dasar.
 * Harga per satuan dasar TIDAK disimpan di sini — ia diturunkan dari subtotal ÷ qty saat
 * memperbarui harga beli master, supaya tidak ada dua angka yang bisa berbeda.
 */
#[Fillable([
    'purchase_order_id', 'product_id', 'raw_material_id',
    'qty', 'qty_beli', 'satuan_beli', 'isi_per_satuan_beli',
    'qty_diterima', 'harga_satuan', 'subtotal',
])]
class PurchaseOrderItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'qty_beli' => 'decimal:3',
            // POTRET satuan saat nota disimpan, sengaja string biasa dan BUKAN enum
            // Satuan: nota lama harus tetap terbaca kalau suatu hari ada case enum yang
            // diganti namanya atau dibuang. Nota yang gagal dibuka karena satuannya tidak
            // dikenali lagi sama saja dengan nota yang hilang.
            'isi_per_satuan_beli' => 'decimal:3',
            'qty_diterima' => 'decimal:3',
            'harga_satuan' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    /** Harga per satuan DASAR menurut baris ini; null kalau tidak bisa dihitung. */
    public function hargaPerSatuanDasar(): ?float
    {
        $qty = (float) $this->qty;

        if ($qty <= 0) {
            return null;
        }

        return (float) $this->subtotal / $qty;
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
