<?php

namespace App\Actions\Pembelian;

use App\Models\Pembelian\PurchaseOrder;

/**
 * Menyiapkan isian nota belanja baru dari nota TERAKHIR — "sama seperti belanja terakhir".
 *
 * KENAPA ADA. Warung kulakan dua sampai tiga kali sepekan dari grosir yang sama, dan
 * barangnya hampir sama tiap kali. Mengetik ulang empat puluh baris — nama, jumlah, harga —
 * setiap kali adalah pekerjaan manual terbesar yang tersisa di aplikasi ini, dan pekerjaan
 * manual terbesar adalah alasan orang berhenti mencatat lalu kembali ke buku.
 *
 * YANG DIKEMBALIKAN ISIAN, BUKAN NOTA. Aksi ini tidak menyimpan apa pun. Ia mengisi kotak-
 * kotak di formulir supaya orangnya MELIHAT dan mengubah dulu — dan itu bukan kehati-hatian
 * berlebihan: harga grosir berubah tiap pekan, dan nota yang tersimpan otomatis dengan harga
 * pekan lalu menaruh angka yang salah ke dalam hitungan modal seluruh barang.
 *
 * YANG SENGAJA TIDAK DISALIN, dan tiap butirnya punya sebab:
 *  - TANGGAL. Nota baru bertanggal hari ini. Menyalin tanggal lama membuat stok masuk ke
 *    hari yang sudah lewat, dan laporan hari itu berubah sesudah ditutup.
 *  - POTONGAN & ONGKOS KIRIM. Keduanya kesepakatan sekali jalan; menyalinnya berarti
 *    memasukkan potongan yang tidak diberikan siapa pun ke dalam hitungan.
 *  - FOTO BUKTI. Struk pekan lalu bukan bukti belanja pekan ini.
 *  - CATATAN. Isinya biasanya alasan yang cuma berlaku sekali ("titip di warung Bu Sri").
 *
 * YANG DISALIN: barangnya, jumlahnya, harganya, dan nama tempat belanjanya — empat hal yang
 * memang berulang.
 */
class SalinNotaTerakhirAction
{
    /**
     * @param  ?string  $outletId  nota terakhir DI CABANG INI; null = cabang mana pun
     * @return null|array{
     *     jumlah: array<string, string>,
     *     harga: array<string, string>,
     *     beliDari: string,
     *     nomor: string,
     *     tanggal: string,
     *     dilewati: int,
     * }
     */
    public function untuk(?string $outletId = null): ?array
    {
        $nota = PurchaseOrder::query()
            ->with(['items', 'supplier'])
            /*
             * Nota terakhir DI CABANG YANG SAMA, bukan di cabang mana pun.
             *
             * Barang yang dikulak cabang A bisa berbeda jauh dari cabang B, dan mengisi
             * formulir cabang B dengan daftar cabang A membuat orangnya harus mengosongkan
             * empat puluh kotak — lebih lama daripada mengetik dari nol.
             */
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
            ->latest('tanggal')
            ->latest('created_at')
            ->first();

        if ($nota === null || $nota->items->isEmpty()) {
            return null;
        }

        $jumlah = [];
        $harga = [];
        $dilewati = 0;

        foreach ($nota->items as $baris) {
            $kunci = $baris->product_id ?? $baris->raw_material_id;

            /*
             * Baris yang barangnya sudah tidak ada DILEWATI dan DIHITUNG.
             *
             * Barang bisa dihapus sesudah nota lama dibuat (soft delete), dan kuncinya lalu
             * tidak cocok dengan satu pun kotak di formulir — isian yang diam-diam hilang
             * membuat orangnya menyimpan nota yang kurang satu barang tanpa pernah tahu.
             * Jumlahnya dikembalikan supaya layar bisa mengatakannya.
             */
            if ($kunci === null) {
                $dilewati++;

                continue;
            }

            /*
             * Nilainya TEKS DIGIT, bukan float — sama seperti yang dikirim kotak uang di
             * layar. Properti $jumlah dan $harga di formulir sengaja bertipe mixed berisi
             * teks; menaruh float di situ membuat "58000.0" muncul di kotak yang seharusnya
             * berbunyi "58.000", dan pembacaan uangnya ikut berubah artinya.
             */
            $jumlah[$kunci] = $this->angkaRapi($baris->qty_beli);
            $harga[$kunci] = $this->angkaRapi($baris->harga_satuan);
        }

        if ($jumlah === []) {
            return null;
        }

        return [
            'jumlah' => $jumlah,
            'harga' => $harga,
            /*
             * Nama tempat belanja diambil dari RELASI supplier, bukan dari kolom di nota.
             *
             * `beli_dari` cuma nama medan di muatan CatatPembelianAction; yang tersimpan
             * `supplier_id`. Membacanya sebagai kolom mengembalikan null diam-diam — kotak
             * "beli dari" tetap kosong dan orangnya mengetiknya lagi tiap kali, yaitu
             * pengetikan yang justru mau dihilangkan fitur ini. Ketahuan dari uji, bukan
             * dari membaca kode.
             */
            'beliDari' => (string) ($nota->supplier?->nama ?? ''),
            'nomor' => (string) $nota->nomor_po,
            'tanggal' => $nota->tanggal->locale('id')->translatedFormat('j M Y'),
            'dilewati' => $dilewati,
        ];
    }

    /**
     * Angka desimal(15,3) → teks yang enak dibaca orang.
     *
     * "2.000" dari kolom qty harus jadi "2", bukan "2.000" — yang di kolom jumlah terbaca
     * dua ribu. Nol di belakang koma dibuang; yang benar-benar berdesimal (0,25 kg lele)
     * dipertahankan dengan koma sebagai pemisah, sesuai cara orang menulisnya di sini.
     */
    private function angkaRapi(mixed $nilai): string
    {
        /*
         * Satu jalur untuk keduanya, dan itu TERBUKTI cukup — bukan disederhanakan asal.
         *
         * Sempat ada cabang khusus untuk angka bulat, lalu uji mutasi menunjukkan
         * membuangnya tidak mengubah satu pun hasil: number_format(10, 3) = "10,000",
         * dibuang nolnya jadi "10," lalu komanya jadi "10" — persis yang dihasilkan cabang
         * khusus tadi. Cabang yang tidak mengubah apa pun cuma menambah tempat untuk salah.
         *
         * Yang dijaga jalur ini: "10.000" dari kolom decimal(15,3) TIDAK boleh sampai ke
         * kotak jumlah apa adanya — di situ ia terbaca sepuluh ribu, dan notanya tersimpan
         * dengan sepuluh ribu karung beras.
         */
        return rtrim(rtrim(number_format((float) $nilai, 3, ',', ''), '0'), ',');
    }
}
