<?php

namespace App\Actions\Stock;

use App\Models\Scopes\TenantScope;
use App\Models\Stock;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Mencari baris stok satu item di satu outlet, dan membuatnya dengan saldo 0 kalau
 * outlet itu belum pernah mencatatnya.
 *
 * Satu-satunya tempat baris `stocks` dibuat. Ada beberapa jalur yang membutuhkannya —
 * penjualan offline atas produk yang baru dibuat owner, penyetelan ambang minimum dari
 * formulir produk, dan opname — dan tiga salinan kode pembuatan baris berarti cepat atau
 * lambat salah satunya lupa mengisi tenant_id atau memakai kombinasi product/raw_material
 * yang tidak sah.
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
        if ($stock = $this->cari($tenantId, $outletId, $productId, $rawMaterialId)) {
            return $stock;
        }

        try {
            return $this->buat($tenantId, $outletId, $productId, $rawMaterialId);
        } catch (UniqueConstraintViolationException $bentrok) {
            // BALAPAN: antara cari() dan buat() di atas, proses lain sudah membuat baris
            // yang sama persis dan unique index (outlet_id, product_id) /
            // (outlet_id, raw_material_id) menolak yang kedua.
            //
            // Ini bukan keadaan langka yang teoretis: dua tablet kasir yang menjual barang
            // yang sama, saat barangnya belum pernah punya baris stok di outlet itu, tiba
            // di sini bersamaan. `firstOrCreate` TIDAK cukup — balapannya di tingkat basis
            // data, bukan di tingkat PHP, jadi ia melempar pelanggaran yang sama.
            //
            // Yang dilakukan: ambil baris yang menang. JANGAN melempar. Aksi ini dipanggil
            // dari jalur penjualan, dan penjualan tidak pernah boleh gagal karena stok
            // (aturan 5 CLAUDE.md) — barangnya sudah keluar dari rak dan uangnya sudah
            // diterima; galat di sini hanya membuat penjualan sah itu hilang dari catatan.
            //
            // Kalau baris pemenangnya tetap tidak ketemu, pelanggarannya dilempar ulang apa
            // adanya: berarti sebabnya bukan balapan ini, dan menelannya diam-diam akan
            // menyembunyikan cacat yang lain.
            return $this->cari($tenantId, $outletId, $productId, $rawMaterialId)
                ?? throw $bentrok;
        }
    }

    /**
     * Tenant dibaca dari ARGUMEN, bukan dari TenantContext ambient.
     *
     * Aksi ini juga dipanggil dari jalur sinkronisasi offline yang berjalan tanpa
     * TenantContext terpasang, dan di situ `shouldScope()` bernilai false — artinya
     * global scope tidak memfilter apa pun dan pencarian tanpa filter eksplisit bisa
     * memungut baris milik tenant lain. Karena itu filternya ditulis sendiri di sini dan
     * global scope-nya dilepas, supaya hasilnya sama persis baik ada konteks maupun tidak.
     */
    private function cari(
        string $tenantId,
        string $outletId,
        ?string $productId,
        ?string $rawMaterialId,
    ): ?Stock {
        $query = Stock::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('outlet_id', $outletId);

        $productId === null
            ? $query->whereNull('product_id')
            : $query->where('product_id', $productId);

        $rawMaterialId === null
            ? $query->whereNull('raw_material_id')
            : $query->where('raw_material_id', $rawMaterialId);

        return $query->first();
    }

    private function buat(
        string $tenantId,
        string $outletId,
        ?string $productId,
        ?string $rawMaterialId,
    ): Stock {
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
