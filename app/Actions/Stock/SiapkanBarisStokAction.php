<?php

namespace App\Actions\Stock;

use App\Models\Stock;

/**
 * Mencari baris stok satu item di satu outlet, dan membuatnya dengan saldo 0 kalau
 * outlet itu belum pernah mencatatnya.
 *
 * Satu-satunya tempat baris `stocks` dibuat. Ada beberapa jalur yang membutuhkannya —
 * penjualan offline atas produk yang baru dibuat owner, penyetelan ambang minimum dari
 * formulir produk, dan nanti opname — dan tiga salinan kode pembuatan baris berarti
 * cepat atau lambat salah satunya lupa mengisi tenant_id atau memakai kombinasi
 * product/raw_material yang tidak sah.
 *
 * Yang TIDAK dilakukan di sini: mengubah saldo. Membuat baris berarti saldonya 0 dan
 * belum ada apa pun yang bergerak, jadi tidak ada mutasi yang dicatat. Perubahan saldo
 * hanya lewat AdjustStockAction.
 */
class SiapkanBarisStokAction
{
    public function execute(
        string $tenantId,
        string $outletId,
        ?string $productId = null,
        ?string $rawMaterialId = null,
    ): Stock {
        $query = Stock::query()->where('outlet_id', $outletId);

        $productId === null
            ? $query->whereNull('product_id')
            : $query->where('product_id', $productId);

        $rawMaterialId === null
            ? $query->whereNull('raw_material_id')
            : $query->where('raw_material_id', $rawMaterialId);

        if ($stock = $query->first()) {
            return $stock;
        }

        $stock = new Stock([
            'outlet_id' => $outletId,
            'product_id' => $productId,
            'raw_material_id' => $rawMaterialId,
            'jumlah_saat_ini' => 0,
        ]);

        // tenant_id tidak pernah fillable; diisi eksplisit karena aksi ini juga dipakai
        // dari jalur sinkronisasi yang tidak selalu punya TenantContext terpasang.
        $stock->tenant_id = $tenantId;
        $stock->save();

        return $stock;
    }
}
