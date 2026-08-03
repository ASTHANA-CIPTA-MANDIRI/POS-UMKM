<?php

namespace App\Actions\Stock;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Transaction;

/**
 * Menurunkan stok akibat penjualan.
 *
 * Dua perilaku berbeda sesuai jenis produk:
 * - Produk berbasis RESEP (menu FnB) → yang berkurang adalah stok BAHAN BAKU
 *   sesuai porsi terjual, bukan stok produk jadi.
 * - Produk biasa → stok produk berkurang, dikonversi dulu ke satuan dasar
 *   (mis. jual 1 dus = 12 pcs keluar).
 *
 * Stok DIBIARKAN menjadi negatif kalau catatan awalnya kurang. Penjualan offline
 * yang baru masuk belakangan sudah benar-benar terjadi secara fisik, jadi menolak
 * mutasinya akan membuat kartu stok bohong; selisihnya diselesaikan lewat opname.
 */
class ApplySaleToStockAction
{
    public function __construct(private AdjustStockAction $adjust) {}

    /** @return array<int, StockMovement> */
    public function execute(Transaction $transaction, ?string $olehUserId = null): array
    {
        $movements = [];

        $transaction->loadMissing('items.product.recipeItems');

        foreach ($transaction->items as $item) {
            $product = $item->product;

            if ($product === null) {
                continue;
            }

            $qty = (float) $item->qty;

            if ($product->usesRecipe()) {
                foreach ($product->recipeItems as $recipeItem) {
                    $stock = $this->resolveStock(
                        $transaction->tenant_id,
                        $transaction->outlet_id,
                        rawMaterialId: $recipeItem->raw_material_id,
                    );

                    $movements[] = $this->adjust->execute(
                        $stock,
                        StockMovementType::Keluar,
                        -$recipeItem->kebutuhanUntuk($qty),
                        $transaction,
                        $olehUserId,
                        "Pemakaian bahan baku dari penjualan {$transaction->nomor_transaksi}",
                    );
                }

                continue;
            }

            if (! $product->lacak_stok) {
                continue;
            }

            $stock = $this->resolveStock(
                $transaction->tenant_id,
                $transaction->outlet_id,
                productId: $product->getKey(),
            );

            $movements[] = $this->adjust->execute(
                $stock,
                StockMovementType::Keluar,
                -$this->qtyDalamSatuanDasar($product, $qty),
                $transaction,
                $olehUserId,
                "Penjualan {$transaction->nomor_transaksi}",
            );
        }

        return $movements;
    }

    private function qtyDalamSatuanDasar(Product $product, float $qty): float
    {
        return $product->keSatuanDasar($qty);
    }

    /**
     * Baris stok dibuat otomatis kalau outlet belum pernah mencatat item ini —
     * kalau tidak, penjualan offline atas produk baru akan gagal disinkronkan.
     */
    private function resolveStock(
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

        $stock->tenant_id = $tenantId;
        $stock->save();

        return $stock;
    }
}
