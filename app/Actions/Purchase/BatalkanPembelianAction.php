<?php

namespace App\Actions\Purchase;

use App\Actions\Stock\AdjustStockAction;
use App\Actions\Stock\SiapkanBarisStokAction;
use App\Enums\DocumentStatus;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\RawMaterial;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan nota pembelian: stok dikembalikan, harga beli dipulihkan, notanya TETAP ADA.
 *
 * Notanya tidak dihapus, dan itu bukan kehati-hatian berlebihan. Nota yang lenyap membuat
 * kartu stok memuat mutasi masuk dan mutasi keluar yang sama-sama menunjuk dokumen yang
 * tidak ada lagi — dan sebulan kemudian tidak ada seorang pun yang bisa menjawab kenapa
 * angkanya sempat naik lalu turun. Yang dibatalkan tetap terbaca di daftar, berlencana
 * "Dibatalkan".
 *
 * IDEMPOTEN, dan ini bukan kerapian: tombol yang ditekan dua kali (jaringan lambat, jari
 * yang tidak yakin) akan mengurangi stok DUA KALI, dan angka yang dihasilkan tidak
 * melanggar aturan apa pun — stok memang boleh minus di POS ini, jadi tidak ada galat,
 * tidak ada penolakan, hanya saldo yang salah dan mutasi kedua yang terbaca sah di kartu
 * stok. Penjaganya: status dibaca ULANG di dalam lock baris nota, bukan dari model yang
 * sudah dimuat lebih dulu.
 */
class BatalkanPembelianAction
{
    public function __construct(
        private SiapkanBarisStokAction $siapkanStok,
        private AdjustStockAction $adjust,
    ) {}

    /**
     * @return bool true kalau pembatalannya benar-benar terjadi pada pemanggilan ini;
     *              false kalau notanya memang sudah dibatalkan sebelumnya.
     */
    public function execute(PurchaseOrder $po, User $oleh): bool
    {
        return DB::transaction(function () use ($po, $oleh): bool {
            /*
             * Dibaca ulang DI DALAM lock. Memakai $po->status apa adanya membuat dua
             * permintaan yang tiba bersamaan sama-sama melihat "Diterima" dan sama-sama
             * mengembalikan stok.
             */
            $terkunci = PurchaseOrder::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereKey($po->getKey())
                ->lockForUpdate()
                ->first();

            if ($terkunci === null || $terkunci->status === DocumentStatus::Dibatalkan) {
                return false;
            }

            // Stok hanya dikembalikan kalau notanya memang pernah menggerakkannya. Nota
            // yang belum diterima (data lama berstatus draft) tidak punya mutasi masuk,
            // jadi mengeluarkan barangnya akan mengarang pengurangan yang tidak berpasangan.
            $pernahMasuk = $terkunci->status->movesStock();

            foreach ($terkunci->items as $item) {
                if ($pernahMasuk) {
                    $this->kembalikanStok($terkunci, $item, $oleh);
                }

                $this->pulihkanHargaBeli($terkunci, $item);
            }

            $terkunci->status = DocumentStatus::Dibatalkan;
            $terkunci->save();

            return true;
        });
    }

    /**
     * Mutasi Keluar berjumlah negatif, referensinya nota yang SAMA.
     *
     * Referensi yang sama disengaja: kartu stok jadi memperlihatkan pasangan masuk-keluar
     * yang menunjuk satu dokumen, dan itu satu-satunya cara membaca "barang ini pernah
     * masuk lewat nota itu, lalu dikembalikan karena notanya dibatalkan".
     */
    private function kembalikanStok(PurchaseOrder $po, PurchaseOrderItem $item, User $oleh): void
    {
        $qty = (float) $item->qty;

        if ($qty <= 0) {
            return;
        }

        $stok = $this->siapkanStok->execute(
            $po->tenant_id,
            $po->outlet_id,
            $item->product_id,
            $item->raw_material_id,
        );

        $this->adjust->execute(
            $stok,
            StockMovementType::Keluar,
            -$qty,
            referensi: $po,
            olehUserId: $oleh->getKey(),
            catatan: 'Pembatalan nota '.$po->nomor_po,
            alasan: null,
        );
    }

    /**
     * Harga beli dikembalikan ke nota Diterima TERAKHIR YANG TERSISA.
     *
     * Aturannya "harga terakhir menang", jadi membatalkan nota terakhir harus mengembalikan
     * harga ke nota sebelum itu — bukan membiarkan harga dari nota yang sudah dinyatakan
     * tidak pernah terjadi. Kalau tidak ada nota lain yang tersisa, harganya DIBIARKAN:
     * menolkannya berarti menyatakan barangnya tidak bernilai, dan itu langsung menghapus
     * nilai persediaannya di layar stok.
     *
     * Hanya baris yang harganya di atas nol yang diproses. Baris berharga nol (bonus
     * grosir) tidak pernah menimpa harga apa pun saat notanya disimpan, jadi memulihkan
     * karenanya akan menimpa harga yang mungkin diketik tangan oleh pemiliknya.
     */
    private function pulihkanHargaBeli(PurchaseOrder $po, PurchaseOrderItem $item): void
    {
        if ((float) $item->subtotal <= 0) {
            return;
        }

        $sebelumnya = PurchaseOrderItem::query()
            ->withoutGlobalScope(TenantScope::class)
            ->select('purchase_order_items.*')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.tenant_id', $po->tenant_id)
            ->where('purchase_orders.status', DocumentStatus::Diterima->value)
            ->whereNull('purchase_orders.deleted_at')
            ->where('purchase_order_items.purchase_order_id', '!=', $po->getKey())
            ->when(
                $item->product_id !== null,
                fn ($q) => $q->where('purchase_order_items.product_id', $item->product_id),
                fn ($q) => $q->where('purchase_order_items.raw_material_id', $item->raw_material_id),
            )
            ->where('purchase_order_items.subtotal', '>', 0)
            ->where('purchase_order_items.qty', '>', 0)
            ->orderByDesc('purchase_orders.diterima_pada')
            ->orderByDesc('purchase_orders.created_at')
            ->first();

        if ($sebelumnya === null) {
            return;
        }

        $harga = $sebelumnya->hargaPerSatuanDasar();

        if ($harga === null) {
            return;
        }

        $barang = $item->product_id !== null
            ? Product::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereKey($item->product_id)
                ->first()
            : RawMaterial::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereKey($item->raw_material_id)
                ->first();

        if ($barang === null) {
            return;
        }

        if ($barang instanceof Product) {
            $barang->harga_beli = round($harga, 2);
        } else {
            $barang->harga_beli_terakhir = round($harga, 2);
        }

        $barang->save();
    }
}
