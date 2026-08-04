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
 *
 * Penjualan yang tiba SESUDAH opname tapi terjadi SEBELUMNYA tetap diterapkan, dengan
 * penanda perlu_diperiksa + catatan — lihat catatanMutasi().
 */
class ApplySaleToStockAction
{
    public function __construct(
        private AdjustStockAction $adjust,
        private SiapkanBarisStokAction $siapkanStok,
    ) {}

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
                        $this->catatanMutasi(
                            $stock,
                            $transaction,
                            "Pemakaian bahan baku dari penjualan {$transaction->nomor_transaksi}",
                        ),
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
                $this->catatanMutasi($stock, $transaction, "Penjualan {$transaction->nomor_transaksi}"),
            );
        }

        return $movements;
    }

    /**
     * Penanda "penjualan datang setelah opname".
     *
     * Mutasinya TETAP diterapkan. Penjualannya sungguh terjadi — barangnya sudah keluar
     * dari rak dan uangnya sudah masuk — jadi menolaknya akan membuat kartu stok bohong
     * (aturan 5 CLAUDE.md: stok boleh minus, penjualan jangan pernah diblokir).
     *
     * Tapi ada satu keadaan yang berbahaya justru karena tidak kelihatan salah:
     * transaksi offline jam 09.00 yang baru disinkronkan jam 11.00, sedangkan opname
     * dilakukan jam 10.00. Barang yang terjual jam 09.00 SUDAH tidak ada di rak saat
     * dihitung, jadi opname sudah memotongnya sekali; mutasi penjualan ini
     * memotongnya lagi. Stok berakhir kurang sebanyak barang yang terjual pagi itu,
     * tanpa galat, tanpa angka minus yang mencolok, dan tanpa satu pun jejak yang
     * menjelaskannya. Selisihnya baru muncul di opname berikutnya sebagai "hilang".
     *
     * Karena itu barisnya ditandai perlu_diperiksa dan mutasinya membawa penanda di
     * catatan — dua-duanya, karena bendera memberi tahu "periksa ini" sedangkan catatan
     * memberi tahu "kenapa".
     */
    private function catatanMutasi(Stock $stock, Transaction $transaction, string $catatan): string
    {
        $opname = $stock->opname_terakhir_pada;
        $waktu = $transaction->waktu_transaksi;

        if ($opname === null || $waktu === null || ! $waktu->lt($opname)) {
            return $catatan;
        }

        // Hanya kolom bendera yang ikut tersimpan (jumlah_saat_ini tidak pernah disentuh
        // di sini), jadi ini bukan jalur kedua yang mengubah saldo.
        $stock->perlu_diperiksa = true;
        $stock->save();

        return $catatan.sprintf(
            ' [PERLU DIPERIKSA] Transaksi terjadi %s, SEBELUM opname terakhir %s, dan baru disinkronkan sekarang. '
            .'Barangnya kemungkinan sudah ikut terhitung saat opname, jadi stok bisa terpotong dua kali.',
            $waktu->format('d/m/Y H:i'),
            $opname->format('d/m/Y H:i'),
        );
    }

    private function qtyDalamSatuanDasar(Product $product, float $qty): float
    {
        return $product->keSatuanDasar($qty);
    }

    /**
     * Baris stok dibuat otomatis kalau outlet belum pernah mencatat item ini —
     * kalau tidak, penjualan offline atas produk baru akan gagal disinkronkan.
     *
     * Pembuatan barisnya dititipkan ke SiapkanBarisStokAction supaya jalur lain yang
     * butuh hal sama (setel ambang minimum dari formulir produk, opname) memakai kode
     * yang sama persis, bukan salinan yang bisa menyimpang.
     */
    private function resolveStock(
        string $tenantId,
        string $outletId,
        ?string $productId = null,
        ?string $rawMaterialId = null,
    ): Stock {
        return $this->siapkanStok->execute($tenantId, $outletId, $productId, $rawMaterialId);
    }
}
