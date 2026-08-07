<?php

namespace App\Actions\Purchase;

use App\Actions\Stock\AdjustStockAction;
use App\Actions\Stock\SiapkanBarisStokAction;
use App\Enums\DocumentStatus;
use App\Enums\StockMovementType;
use App\Models\Bahan\RawMaterial;
use App\Models\Pembelian\PurchaseOrder;
use App\Models\Pembelian\PurchaseOrderItem;
use App\Models\Produk\Product;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\DB;

/**
 * Menandai nota belanja "barangnya sudah datang": stok masuk, harga beli master diperbarui.
 *
 * SATU-SATUNYA tempat pembelian menaikkan stok. Sebelum aksi ini ada, menyimpan nota
 * LANGSUNG menambah stok — jadi belanja yang dicatat hari ini untuk barang yang datang tujuh
 * hari kemudian membuat saldo mengaku ada barang yang belum sampai di rak selama seminggu,
 * dan layar kasir mengabari "Aman" untuk barang yang raknya kosong. Kasir lalu menjanjikannya
 * ke pembeli.
 *
 * Yang TIDAK dibangun, dan itu disengaja: aksi ini tidak pernah MENAHAN penjualan. Nota yang
 * belum datang cuma berarti "angkanya belum masuk saldo" — kasir tetap bisa menjual apa pun
 * (aturan 5 CLAUDE.md), ia hanya tidak pernah dikabari bahwa barangnya tersedia.
 *
 * KENAPA AKSI TERPISAH, bukan cabang `if` di dalam CatatPembelianAction:
 * dengan aksi sendiri, AdjustStockAction dan SiapkanBarisStokAction punya TEPAT SATU
 * pemanggil dari jalur pembelian. Cabang `if` di tengah aksi 400 baris adalah cabang yang
 * suatu hari dibalik oleh perbaikan yang tidak berhubungan, tanpa satu pun galat saat itu
 * terjadi — dan akibatnya baru terbaca sebulan kemudian sebagai saldo yang tidak bisa
 * dijelaskan.
 *
 * TIGA KEPUTUSAN YANG MENENTUKAN ANGKA:
 *
 * 1. **Jumlah yang masuk adalah POTRET di baris nota (`item->qty`), bukan hitungan ulang
 *    dari master.** `isi_per_satuan` produk boleh berubah kapan saja (grosir ganti kemasan
 *    dari isi 12 jadi isi 10), dan menghitung ulang saat penerimaan berarti 2 dus yang
 *    dicatat sebagai 24 pcs masuk sebagai 20 pcs. Selisih 4 pcs itu tidak melanggar aturan
 *    apa pun: tidak ada galat, tidak ada penolakan, hanya saldo yang salah selamanya.
 *
 * 2. **Harga beli master diperbarui SAAT DITERIMA, bukan saat nota disimpan.** Nilai
 *    persediaan di layar Stok = saldo × harga_beli, dan aturannya "harga terakhir menang".
 *    Memperbarui harga saat nota disimpan berarti menilai ulang barang yang SUDAH di rak
 *    dengan harga barang yang BELUM dibeli — angkanya melompat tanpa satu barang pun
 *    berpindah, dan pemiliknya membaca itu sebagai aplikasi yang salah hitung.
 *
 * 3. **Outlet penerimaan dibaca dari notanya (`$po->outlet_id`), bukan dari dropdown layar.**
 *    Aksi ini karena itu tidak punya parameter outlet sama sekali. Barang yang masuk ke
 *    cabang yang tidak disaksikan pemiliknya adalah cacat yang sudah pernah terjadi di
 *    lembar hitung stok.
 *
 * IDEMPOTEN, dengan pola yang sama persis seperti BatalkanPembelianAction: status dibaca
 * ULANG di dalam `lockForUpdate()`, bukan dari model yang sudah dimuat lebih dulu. Tanpa itu
 * tombol yang ditekan dua kali (jaringan lambat, jari yang tidak yakin) menambah stok DUA
 * KALI, dan angkanya tidak melanggar aturan apa pun — stok memang boleh berapa saja di POS
 * ini, jadi tidak ada galat, hanya saldo yang salah dan mutasi kedua yang terbaca sah di
 * riwayat barang.
 */
class TerimaPembelianAction
{
    /**
     * Kalimat yang WAJIB tampil di blok konfirmasi "tandai datang".
     *
     * Terima SEBAGIAN sengaja tidak dibangun: `qty_diterima` selalu diisi penuh. Tapi
     * keadaan "yang datang cuma 8 dari 10" nyata dan pasti terjadi, dan tanpa satu kalimat
     * yang menyebut jalan keluarnya pemiliknya mengarang jalannya sendiri — biasanya dengan
     * mencatat nota kedua bernilai minus, yang tidak ada wujudnya di aplikasi ini.
     *
     * Ditaruh di sini, bukan diketik di Blade, supaya layar daftar dan layar rincian tidak
     * pernah memberi dua nasihat yang berbeda untuk satu keadaan.
     */
    public const CATATAN_TERIMA_SEBAGIAN = 'Kalau yang datang tidak sama dengan nota, tandai datang dulu lalu betulkan lewat Hitung stok.';

    public function __construct(
        private SiapkanBarisStokAction $siapkanStok,
        private AdjustStockAction $adjust,
    ) {}

    /**
     * @return bool true kalau penerimaannya benar-benar terjadi pada pemanggilan ini;
     *              false kalau notanya sudah diterima ATAU sudah dibatalkan sebelumnya.
     */
    public function execute(PurchaseOrder $po, User $oleh): bool
    {
        return DB::transaction(function () use ($po, $oleh): bool {
            /*
             * Dibaca ulang DI DALAM lock. Memakai $po->status apa adanya membuat dua
             * permintaan yang tiba bersamaan sama-sama melihat "belum datang" dan
             * sama-sama menambah stok.
             */
            $terkunci = PurchaseOrder::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereKey($po->getKey())
                ->lockForUpdate()
                ->first();

            if ($terkunci === null) {
                return false;
            }

            // Sudah diterima → tidak ada yang perlu dikerjakan. Sudah dibatalkan → notanya
            // sudah dinyatakan tidak pernah terjadi; menerimanya berarti memasukkan barang
            // yang dokumennya sendiri sudah dibatalkan.
            if ($terkunci->status === DocumentStatus::Diterima || $terkunci->status === DocumentStatus::Dibatalkan) {
                return false;
            }

            foreach ($terkunci->items as $item) {
                $this->masukkanStok($terkunci, $item, $oleh);
                $this->perbaruiHargaBeli($item);
            }

            $terkunci->status = DocumentStatus::Diterima;
            $terkunci->diterima_pada = now();
            $terkunci->save();

            // Model yang dipegang pemanggil ikut disegarkan: tanpa ini layar masih
            // memperlihatkan "belum datang" sesudah tombolnya berhasil ditekan, dan
            // pemiliknya menekan tombolnya lagi.
            $po->status = $terkunci->status;
            $po->diterima_pada = $terkunci->diterima_pada;
            $po->syncOriginalAttributes(['status', 'diterima_pada']);

            return true;
        });
    }

    /**
     * Satu baris nota → satu mutasi Masuk sebesar POTRET satuan dasarnya.
     *
     * `qty_diterima` diisi penuh sebesar `qty`: terima sebagian tidak dibangun, dan
     * selisihnya diselesaikan lewat hitung stok (lihat CATATAN_TERIMA_SEBAGIAN). Mengisinya
     * separuh tanpa ada layar yang bisa mengubahnya nanti hanya menciptakan angka yang tidak
     * pernah bisa dibetulkan.
     */
    private function masukkanStok(PurchaseOrder $po, PurchaseOrderItem $item, User $oleh): void
    {
        $qty = (float) $item->qty;

        if ($qty <= 0) {
            return;
        }

        // Baris `stocks` dibuat kalau outlet ini belum pernah mencatat barangnya. Inilah
        // sebabnya nota yang BELUM datang tidak boleh melewati aksi ini: membuat barisnya
        // lebih awal mengubah status barang dari "belum dihitung" menjadi "habis", dan
        // "habis" adalah pernyataan yang dikirim ke layar kasir.
        $stok = $this->siapkanStok->execute(
            $po->tenant_id,
            $po->outlet_id,
            $item->product_id,
            $item->raw_material_id,
        );

        $this->adjust->execute(
            $stok,
            StockMovementType::Masuk,
            $qty,
            referensi: $po,
            olehUserId: $oleh->getKey(),
            catatan: 'Pembelian '.$po->nomor_po.($po->supplier_id !== null ? ' — '.($po->supplier?->nama ?? '') : ''),
            // AlasanOpname tidak berlaku di sini: tipenya (Masuk) sudah menjelaskan
            // sebabnya, dan mengisi kolom alasan membuat laporan selisih penuh baris
            // yang bukan selisih.
            alasan: null,
        );

        $item->qty_diterima = $qty;
        $item->save();
    }

    /**
     * Harga beli master ditimpa dengan harga nota ini, PER SATUAN DASAR.
     *
     * Per satuan dasar, bukan per satuan beli: 2 dus @58.000 isi 12 = 4.833,33 per pcs.
     * Menyimpan 58.000 tidak memunculkan satu pun galat, tapi nilai persediaan melar 12 kali
     * lipat dan setiap keputusan harga jual yang dibuat dari angka itu salah.
     *
     * Baris berharga nol dilewati: hadiah/bonus grosir ("beli 10 dapat 1") tidak menyatakan
     * apa pun tentang harga barangnya, dan menimpakan nol menghapus harga yang benar —
     * barangnya lalu terbaca tidak bernilai di seluruh laporan persediaan.
     */
    private function perbaruiHargaBeli(PurchaseOrderItem $item): void
    {
        if ((float) $item->subtotal <= 0) {
            return;
        }

        $perSatuanDasar = $item->hargaPerSatuanDasar();

        if ($perSatuanDasar === null || $perSatuanDasar <= 0) {
            return;
        }

        $barang = $this->barang($item);

        if ($barang === null) {
            return;
        }

        if ($barang instanceof Product) {
            $barang->harga_beli = round($perSatuanDasar, 2);
        } else {
            $barang->harga_beli_terakhir = round($perSatuanDasar, 2);
        }

        $barang->save();
    }

    /**
     * Barang dicari tanpa global scope: aksi ini juga dipanggil dari jalur tanpa
     * TenantContext (seeder, perintah artisan), dan di situ scope-nya tidak memfilter apa
     * pun. Yang menjamin tenantnya benar adalah barisnya sendiri — `purchase_order_items`
     * lahir dari nota tenant ini.
     */
    private function barang(PurchaseOrderItem $item): Product|RawMaterial|null
    {
        if ($item->product_id !== null) {
            return Product::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereKey($item->product_id)
                ->first();
        }

        return RawMaterial::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereKey($item->raw_material_id)
            ->first();
    }
}
