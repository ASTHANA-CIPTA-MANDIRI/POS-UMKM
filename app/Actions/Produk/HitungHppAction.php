<?php

namespace App\Actions\Produk;

use App\Models\Produk\Product;

/**
 * Menghitung HPP (harga pokok penjualan) satu produk — SATU rumus, SATU tempat.
 *
 * KENAPA SATU PINTU. Angka ini akan dibaca setidaknya lima layar: saran harga jual, margin
 * per produk, peringatan "diskon ini membuat rugi", laporan barang yang rugi, dan titik
 * impas. Kalau tiap layar menghitung sendiri, cepat atau lambat dua layar menjawab berbeda
 * untuk barang yang sama — dan pemilik tidak punya cara memutuskan mana yang benar. Yang
 * diperdebatkan bukan tampilan; yang diperdebatkan apakah barangnya untung.
 *
 * DUA JENIS PRODUK, DUA CARA HITUNG, dan bedanya tegas:
 *
 *  - BARANG JADI (kelontong, minuman botol): HPP = `products.harga_beli`. Barangnya dibeli
 *    jadi, dijual jadi.
 *  - MENU BERRESEP (warteg): HPP = jumlah dari (jumlah bahan per porsi x harga bahan). Satu
 *    porsi lele goreng dihitung dari 0,25 kg lele + minyak + bumbu, BUKAN dari kolom
 *    harga_beli menu itu — kolom itu memang tidak pernah diisi untuk menu masakan.
 *
 * SUMBER ANGKANYA "HARGA BELI TERAKHIR", bukan rata-rata bergerak, dan itu ASUMSI yang bisa
 * berubah. Alasannya sekarang: angkanya sudah tersimpan (`raw_materials.harga_beli_terakhir`
 * dan `products.harga_beli`, keduanya diperbarui TerimaPembelianAction), bisa dijelaskan ke
 * pemilik warung dengan satu kalimat, dan tidak menuntut kolom baru. Harganya diakui: satu
 * nota mahal membuat seluruh margin bulan itu terlihat jelek sampai ada nota berikutnya.
 * Kalau nanti pemilik memilih rata-rata bergerak, yang berubah HANYA berkas ini dan satu
 * migrasi — itulah gunanya semua layar memanggil aksi ini, bukan menghitung sendiri.
 *
 * HASILNYA MEMBAWA RINCIAN, bukan cuma satu angka. Pemilik yang tidak bisa melihat asal
 * angkanya tidak akan memakainya — ia akan menganggapnya tebakan aplikasi dan kembali
 * menghitung di kertas.
 */
class HitungHppAction
{
    /**
     * @return array{
     *     hpp: ?float,
     *     sumber: 'harga_beli'|'resep'|'tidak_diketahui',
     *     lengkap: bool,
     *     rincian: array<int, array{nama: string, jumlah: float, satuan: string, harga: ?float, subtotal: ?float}>,
     *     bahanTanpaHarga: array<int, string>,
     * }
     */
    public function untuk(Product $produk): array
    {
        return $produk->usesRecipe()
            ? $this->dariResep($produk)
            : $this->dariHargaBeli($produk);
    }

    /** HPP beberapa produk sekaligus, berkunci id — untuk daftar, supaya tidak N+1. */
    public function untukBanyak(iterable $produk): array
    {
        $hasil = [];

        foreach ($produk as $satu) {
            $hasil[$satu->getKey()] = $this->untuk($satu);
        }

        return $hasil;
    }

    /**
     * Margin dari HPP dan harga jual.
     *
     * `null` kalau HPP-nya belum diketahui — BUKAN nol, dan bedanya menentukan apa yang
     * dilihat pemilik. Margin 0% terbaca "barang ini tidak untung sama sekali"; yang benar
     * adalah "belum bisa dihitung karena harga belinya belum diisi". Yang pertama membuatnya
     * menaikkan harga barang yang sebenarnya sudah untung.
     *
     * @return array{rupiah: ?float, persen: ?float, rugi: bool}
     */
    public function margin(?float $hpp, float $hargaJual): array
    {
        if ($hpp === null) {
            return ['rupiah' => null, 'persen' => null, 'rugi' => false];
        }

        $rupiah = round($hargaJual - $hpp, 2);

        /*
         * Persentase dihitung terhadap HARGA JUAL (margin), bukan terhadap HPP (markup).
         *
         * Dua angka yang berbeda dan sangat sering tertukar: modal 10.000 dijual 15.000
         * adalah margin 33% ATAU markup 50% — keduanya benar, untuk pertanyaan yang berbeda.
         * Dipilih margin karena itu yang dipakai saat menyusun harga dari target ("saya mau
         * untung 30% dari yang dibayar pelanggan"), dan saran harga jual nanti memakai rumus
         * yang sama supaya kedua layar tidak pernah berbeda.
         */
        $persen = $hargaJual > 0 ? round(($rupiah / $hargaJual) * 100, 1) : null;

        return ['rupiah' => $rupiah, 'persen' => $persen, 'rugi' => $rupiah < 0];
    }

    /** @return array<string, mixed> */
    private function dariHargaBeli(Product $produk): array
    {
        $harga = $produk->harga_beli !== null ? (float) $produk->harga_beli : null;

        return [
            'hpp' => $harga,
            'sumber' => $harga !== null ? 'harga_beli' : 'tidak_diketahui',
            'lengkap' => $harga !== null,
            'rincian' => [],
            'bahanTanpaHarga' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function dariResep(Product $produk): array
    {
        $baris = $produk->relationLoaded('recipeItems')
            ? $produk->recipeItems
            : $produk->recipeItems()->with('rawMaterial')->get();

        $rincian = [];
        $bahanTanpaHarga = [];
        $total = 0.0;

        foreach ($baris as $item) {
            $bahan = $item->rawMaterial;

            if ($bahan === null) {
                continue;
            }

            $jumlah = (float) $item->jumlah_terpakai;
            $hargaSatuan = $bahan->harga_beli_terakhir !== null ? (float) $bahan->harga_beli_terakhir : null;
            $subtotal = $hargaSatuan !== null ? round($jumlah * $hargaSatuan, 2) : null;

            if ($subtotal === null) {
                // Namanya dikumpulkan supaya layar bisa menyebut BAHAN MANA yang belum
                // berharga. "HPP belum lengkap" tanpa nama membuat pemilik membuka satu per
                // satu enam bahan untuk mencari yang mana.
                $bahanTanpaHarga[] = $bahan->nama;
            } else {
                $total += $subtotal;
            }

            $rincian[] = [
                'nama' => $bahan->nama,
                'jumlah' => $jumlah,
                'satuan' => $bahan->satuan?->value ?? '',
                'harga' => $hargaSatuan,
                'subtotal' => $subtotal,
            ];
        }

        /*
         * Menu yang SEBAGIAN bahannya belum berharga mengembalikan HPP null, bukan jumlah
         * sebagiannya.
         *
         * Ini keputusan yang paling mudah diambil salah. Jumlah sebagian adalah angka yang
         * TERLIHAT sah — Rp 4.000 untuk lele goreng yang bumbunya belum berharga — dan
         * pemilik akan menyimpulkan marginnya 73%. Angka yang salah arah dan tidak bersuara
         * lebih buruk daripada tidak ada angka: yang kedua membuat orang mencari, yang
         * pertama membuatnya tenang.
         *
         * Rinciannya tetap dikembalikan supaya layar bisa memperlihatkan yang SUDAH terhitung
         * beserta yang belum — jadi penolakannya tetap menuntun, bukan sekadar menolak.
         */
        $lengkap = $rincian !== [] && $bahanTanpaHarga === [];

        return [
            'hpp' => $lengkap ? round($total, 2) : null,
            'sumber' => $rincian !== [] ? 'resep' : 'tidak_diketahui',
            'lengkap' => $lengkap,
            'rincian' => $rincian,
            'bahanTanpaHarga' => $bahanTanpaHarga,
        ];
    }
}
