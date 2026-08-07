<?php

namespace Tests\Concerns;

use App\Actions\Purchase\CatatPembelianAction;
use App\Enums\Satuan;
use App\Models\Bahan\RawMaterial;
use App\Models\Pembelian\PurchaseOrder;
use App\Models\Produk\Product;
use App\Models\Stok\Stock;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\User;

/**
 * Penyiapan data yang dipakai berulang oleh berkas uji Pembelian.
 *
 * Dipakai bersama MembuatDataUji (tenant/outlet/user); yang di sini khusus barang, saldo
 * stok, dan pemanggilan aksi pencatatan nota.
 */
trait MembuatDataPembelian
{
    /** @param  array<string, mixed>  $atribut */
    protected function buatProduk(string $nama, array $atribut = []): Product
    {
        return Product::create(array_merge([
            'nama_produk' => $nama,
            'harga_default' => 10000,
            'satuan' => Satuan::Pcs,
        ], $atribut));
    }

    /** @param  array<string, mixed>  $atribut */
    protected function buatBahan(string $nama, array $atribut = []): RawMaterial
    {
        return RawMaterial::create(array_merge([
            'nama' => $nama,
            'satuan' => Satuan::Kg,
        ], $atribut));
    }

    /** @param  array<string, mixed>  $atribut */
    protected function buatStok(Outlet $outlet, Product|RawMaterial $barang, float $jumlah, array $atribut = []): Stock
    {
        return Stock::create(array_merge([
            'outlet_id' => $outlet->getKey(),
            'product_id' => $barang instanceof Product ? $barang->getKey() : null,
            'raw_material_id' => $barang instanceof RawMaterial ? $barang->getKey() : null,
            'jumlah_saat_ini' => $jumlah,
        ], $atribut));
    }

    /**
     * Satu baris nota untuk produk/bahan baku, dengan jumlah dalam satuan BELI.
     *
     * @return array<string, mixed>
     */
    protected function baris(Product|RawMaterial $barang, float $qtyBeli, float $harga): array
    {
        return [
            'product_id' => $barang instanceof Product ? $barang->getKey() : null,
            'raw_material_id' => $barang instanceof RawMaterial ? $barang->getKey() : null,
            'qty_beli' => $qtyBeli,
            'harga_satuan' => $harga,
        ];
    }

    /** @param  array<string, mixed>  $muatan */
    protected function catatNota(Outlet $outlet, User $oleh, array $muatan): PurchaseOrder
    {
        return app(CatatPembelianAction::class)->execute($outlet, $oleh, $muatan);
    }

    /**
     * Nota yang barangnya BELUM DATANG — stok tidak boleh bergerak sama sekali.
     *
     * Muatan tanpa kunci `sudah_datang` berarti "sudah datang" (bawaannya), jadi keadaan ini
     * harus dinyatakan eksplisit. Disediakan di trait supaya seluruh berkas uji pembelian
     * memakai satu jalur yang sama; nota belum-datang yang disusun tangan per berkas cepat
     * atau lambat salah satunya lupa dan hijau karena alasan yang salah.
     *
     * @param  array<string, mixed>  $muatan
     */
    protected function catatNotaBelumDatang(Outlet $outlet, User $oleh, array $muatan): PurchaseOrder
    {
        return $this->catatNota($outlet, $oleh, array_merge($muatan, ['sudah_datang' => false]));
    }

    /** Saldo stok satu barang di satu outlet; null kalau barisnya belum pernah ada. */
    protected function saldo(Outlet $outlet, Product|RawMaterial $barang): ?float
    {
        $stok = Stock::query()
            ->where('outlet_id', $outlet->getKey())
            ->when(
                $barang instanceof Product,
                fn ($q) => $q->where('product_id', $barang->getKey()),
                fn ($q) => $q->where('raw_material_id', $barang->getKey()),
            )
            ->first();

        return $stok === null ? null : (float) $stok->jumlah_saat_ini;
    }
}
