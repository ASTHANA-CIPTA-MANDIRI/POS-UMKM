<?php

namespace App\Actions\Kasir;

use App\Actions\Stock\SusunBarisStokAction;
use App\Models\Product;

/**
 * Menyusun kabar sisa stok untuk PETAK PRODUK di layar kasir.
 *
 * Bentuknya sengaja bukan daftar angka melainkan peta ringkas
 * `product_id => 'habis'|'menipis'`, dan HANYA berisi barang yang perlu dikabarkan.
 *
 * Empat keputusan yang membentuk aksi ini:
 *
 * 1. **Angkanya tidak dikirim, hanya keadaannya.** Angka yang sampai ke layar kasir
 *    sudah pasti basi: kasir lain di perangkat lain menjual barang yang sama, dan
 *    perangkat ini bisa saja offline sejak sejam lalu. "Sisa 2" yang salah membuat
 *    kasir menjanjikan angka yang tidak ada — sedangkan "Menipis" tetap benar walau
 *    sudah lewat sepuluh transaksi. Yang dibutuhkan kasir cuma dua: jangan menjanjikan
 *    barang yang sudah tidak ada, dan jangan menolak barang yang sebenarnya ada.
 *
 * 2. **Barang 'aman' dan 'belum_dihitung' TIDAK ikut dikirim sama sekali.** Absennya
 *    kunci berarti "tidak ada yang perlu dikabarkan", jadi petaknya tampil apa adanya —
 *    persis seperti sebelum fitur ini ada. Ini juga yang membuat kegagalan jaringan
 *    aman dengan sendirinya: peta kosong = tidak ada lencana, bukan lencana "0".
 *    Barang yang belum pernah dihitung BUKAN barang habis (CLAUDE.md & layar Stok
 *    sudah membedakannya); menandainya merah di kasir berarti warung yang baru
 *    memasukkan 300 barang melihat 300 petak merah, lalu belajar mengabaikan merah.
 *
 * 3. **'minus' dikabarkan sebagai 'habis'.** Bedanya nyata dan tetap dipertahankan di
 *    layar Stok — minus itu masalah pencatatan, habis itu masalah pembelian — tapi
 *    tindakan kasirnya sama persis: periksa rak dulu sebelum menjanjikan. Ini
 *    PEMETAAN untuk ditampilkan, bukan aturan kedua: statusnya tetap diputuskan
 *    Stock::statusStok() lewat SusunBarisStokAction.
 *
 * 4. **Aturan status tidak ditulis ulang di sini.** Barisnya diambil dari
 *    SusunBarisStokAction — sumber yang sama dengan layar Stok & hitung stok — supaya
 *    kasir dan pemilik tidak pernah melihat dua kesimpulan berbeda atas satu barang.
 *    Aksi ini hanya MEMBACA; tidak ada baris `stocks` yang dibuat dan tidak ada saldo
 *    yang berubah.
 */
class SusunSisaStokAction
{
    public function __construct(private SusunBarisStokAction $barisStok) {}

    /**
     * @return array<string, string> product_id => 'habis'|'menipis'
     */
    public function execute(string $outletId): array
    {
        $baris = $this->barisStok->execute($outletId);

        /** @var array<string, string> $sisa */
        $sisa = [];
        /** @var array<string, string> $statusBahan */
        $statusBahan = [];

        foreach ($baris as $item) {
            if ($item['jenis'] === 'bahan') {
                // Bahan nonaktif tetap dicatat: resep yang masih menunjuknya tetap
                // butuh bahan itu, dan menyembunyikan kabarnya hanya membuat menunya
                // tampak aman padahal tidak bisa dimasak.
                $statusBahan[$item['kunci']] = $item['status'];

                continue;
            }

            // Produk nonaktif tidak muncul di katalog kasir, jadi kabarnya tidak akan
            // pernah terpakai — dibuang di sini supaya muatannya tetap kecil.
            if ($item['aktif'] !== true) {
                continue;
            }

            $kabar = $this->kabar($item['status']);

            if ($kabar !== null) {
                $sisa[$item['kunci']] = $kabar;
            }
        }

        return $sisa + $this->menuBerbasisResep($statusBahan);
    }

    /**
     * Menu berbasis resep (warteg, kopi, jus) mengambil keadaan BAHAN BAKUNYA.
     *
     * Baris stok produk jadinya tidak pernah bergerak — yang berkurang saat terjual
     * adalah bahan bakunya (lihat ApplySaleToStockAction) — jadi membaca stok produk
     * jadi akan membuat SELURUH menu tampak habis selamanya. Yang benar-benar
     * menentukan "ayam goreng masih ada atau tidak" adalah ayam di dapur.
     *
     * Aturannya: bahan terparah menentukan. Satu bahan habis = menunya tidak bisa
     * dibuat, seberapa pun penuh bahan lainnya.
     *
     * Bahan yang BELUM PERNAH DIHITUNG diabaikan, bukan dianggap habis. Warung yang
     * belum pernah mencatat bahan bakunya sama sekali karena itu tidak melihat lencana
     * apa pun — keadaan yang sama dengan sebelum fitur ini ada — alih-alih melihat
     * seluruh menunya merah pada hari pertama.
     *
     * @param  array<string, string>  $statusBahan
     * @return array<string, string>
     */
    private function menuBerbasisResep(array $statusBahan): array
    {
        $hasil = [];

        $menu = Product::query()
            ->where('is_active', true)
            ->whereHas('recipeItems')
            ->with('recipeItems:id,product_id,raw_material_id')
            ->get(['id']);

        foreach ($menu as $produk) {
            $terparah = null;

            foreach ($produk->recipeItems as $bahan) {
                $kabar = $this->kabar($statusBahan[$bahan->raw_material_id] ?? 'belum_dihitung');

                if ($kabar === 'habis') {
                    $terparah = 'habis';

                    break;
                }

                if ($kabar === 'menipis') {
                    $terparah = 'menipis';
                }
            }

            if ($terparah !== null) {
                $hasil[$produk->getKey()] = $terparah;
            }
        }

        return $hasil;
    }

    /**
     * Status baris stok → kabar untuk kasir. null berarti tidak perlu dikabarkan.
     *
     * 'aman' dan 'belum_dihitung' sama-sama null tapi karena alasan yang berbeda:
     * yang pertama memang tidak ada masalahnya, yang kedua tidak punya angka yang
     * bisa dipercaya sama sekali. Keduanya berakhir sama di layar — petak apa adanya —
     * dan itu memang jawaban yang benar untuk dua-duanya.
     */
    private function kabar(string $status): ?string
    {
        return match ($status) {
            'minus', 'habis' => 'habis',
            'menipis' => 'menipis',
            default => null,
        };
    }
}
