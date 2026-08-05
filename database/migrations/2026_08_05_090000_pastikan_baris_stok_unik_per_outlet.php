<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menjamin satu baris `stocks` per (outlet, item) — dan membereskan baris kembar
     * yang mungkin sudah terlanjur ada sebelum jaminannya dipasang.
     *
     * Kenapa migrasi ini tetap ada padahal create_stocks_table SUDAH mendeklarasikan
     * kedua unique index-nya: pemeriksaan skema (`Schema::getIndexes('stocks')` di
     * SQLite `:memory:`) memang menemukan `stocks_outlet_id_product_id_unique` dan
     * `stocks_outlet_id_raw_material_id_unique` sudah terpasang, jadi pada basis data
     * yang dibangun dari migrasi repo ini bagian index-nya TIDAK melakukan apa-apa.
     * Yang tidak bisa dipastikan dari sini adalah basis data yang sudah berjalan dan
     * pernah disentuh tangan. Karena itu migrasi ini ditulis sebagai penjamin yang
     * boleh jalan berkali-kali: ia memeriksa dulu, memperbaiki data kalau perlu, lalu
     * memasang index HANYA kalau belum ada. Migrasi yang mati di tengah karena data
     * lama lebih buruk daripada tidak ada migrasi sama sekali.
     *
     * Cara baris kembar digabung, dan alasannya:
     *
     * - **Saldonya DIJUMLAHKAN**, bukan dipilih salah satu. AdjustStockAction selalu
     *   menulis `saldo + delta` dan baris baru selalu lahir dengan saldo 0, jadi saldo
     *   tiap baris kembar persis sama dengan jumlah delta yang mendarat di baris itu.
     *   Menjumlahkannya menghasilkan angka yang sama seperti kalau seluruh mutasi sejak
     *   awal mendarat di satu baris. Memilih "yang mutasinya paling banyak" justru
     *   MEMBUANG pergerakan barang yang sungguh terjadi di baris satunya.
     * - **Induknya baris tertua** (created_at, lalu id sebagai pemutus seri). Yang tertua
     *   adalah yang dilihat semua jalur lain lebih dulu, dan pilihan yang deterministik
     *   membuat hasil migrasi ini bisa diulang dan diperiksa.
     * - **Mutasi dipindahkan LEBIH DULU, baru barisnya dihapus.** `stock_movements.stock_id`
     *   memakai cascadeOnDelete: menghapus baris kembar sebelum mutasinya dipindah akan
     *   ikut menghapus kartu stoknya. Satu-satunya bukti pergerakan barang hilang tanpa
     *   jejak, dan selisihnya baru ketahuan berbulan-bulan kemudian.
     * - **`stok_minimum` diambil yang TERBESAR**: 0 berarti "tidak dipantau", jadi nilai
     *   yang pernah disetel pemilik di salah satu baris tidak boleh hilang tertimpa nol.
     * - **`tanggal_kadaluarsa` diambil yang TERDEKAT** dan `opname_terakhir_pada` yang
     *   TERBARU — dua-duanya arah yang aman: peringatan datang lebih cepat, dan penanda
     *   "sudah dicocokkan fisik" tidak mundur.
     * - **`perlu_diperiksa` dipaksa true** pada baris hasil gabungan. Angkanya memang
     *   sudah dibuat sekonsisten mungkin, tapi barisnya lahir dari keadaan yang tidak
     *   seharusnya ada; menandainya membuat barangnya masuk hitung fisik berikutnya
     *   tanpa perlu ada orang yang mengingatnya. Ini bendera, bukan penghalang —
     *   penjualan tetap jalan.
     */
    public function up(): void
    {
        $this->gabungkanBarisKembar('product_id');
        $this->gabungkanBarisKembar('raw_material_id');

        $this->pastikanIndeksUnik(['outlet_id', 'product_id'], 'stocks_outlet_id_product_id_unique');
        $this->pastikanIndeksUnik(['outlet_id', 'raw_material_id'], 'stocks_outlet_id_raw_material_id_unique');
    }

    /**
     * Sengaja tidak melakukan apa-apa, dan itu bukan kelalaian.
     *
     * Kedua unique index dimiliki oleh `create_stocks_table`, bukan oleh migrasi ini —
     * migrasi ini hanya MEMASTIKAN keduanya ada. Menjatuhkannya di sini berarti
     * `migrate:rollback` satu langkah menghapus jaminan yang dipasang migrasi lain, yaitu
     * persis mengembalikan cacat yang sedang ditutup. Penggabungan barisnya sendiri tidak
     * bisa dibatalkan: begitu dua saldo dijumlahkan, tidak ada catatan yang bisa memecahnya
     * kembali menjadi dua angka semula, dan mengarang pemecahannya lebih buruk daripada
     * membiarkannya.
     */
    public function down(): void
    {
        // tidak ada yang dibatalkan — lihat penjelasan di atas.
    }

    /**
     * @param  array<int, string>  $kolom
     */
    private function pastikanIndeksUnik(array $kolom, string $nama): void
    {
        foreach (Schema::getIndexes('stocks') as $indeks) {
            if (($indeks['unique'] ?? false) && ($indeks['columns'] ?? []) === $kolom) {
                return;
            }
        }

        Schema::table('stocks', function (Blueprint $table) use ($kolom, $nama) {
            $table->unique($kolom, $nama);
        });
    }

    /**
     * NULL pada kolom yang tidak dipakai TIDAK ikut diperiksa (`whereNotNull`).
     *
     * Baik MySQL maupun SQLite menganggap dua NULL berbeda di dalam unique index, jadi
     * baris bahan baku (product_id NULL) bebas berapa pun banyaknya menurut index
     * (outlet_id, product_id) — keunikannya dijaga index yang satunya. Kalau kolom NULL
     * ikut dikelompokkan di sini, SELURUH baris bahan baku di satu outlet akan terbaca
     * sebagai satu rombongan kembar dan digabung jadi satu. Itu bukan perbaikan, itu
     * penghancuran data.
     */
    private function gabungkanBarisKembar(string $kolom): void
    {
        $kembar = DB::table('stocks')
            ->select('outlet_id', $kolom)
            ->whereNotNull($kolom)
            ->groupBy('outlet_id', $kolom)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($kembar as $grup) {
            $baris = DB::table('stocks')
                ->where('outlet_id', $grup->outlet_id)
                ->where($kolom, $grup->{$kolom})
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            if ($baris->count() < 2) {
                continue;
            }

            $induk = $baris->first();
            $idKembar = $baris->slice(1)->pluck('id')->all();

            // Urutannya wajib: pindahkan mutasi dulu (cascadeOnDelete), baru hapus.
            DB::table('stock_movements')
                ->whereIn('stock_id', $idKembar)
                ->update(['stock_id' => $induk->id]);

            DB::table('stocks')->whereIn('id', $idKembar)->delete();

            DB::table('stocks')->where('id', $induk->id)->update([
                'jumlah_saat_ini' => $baris->sum(fn ($b) => (float) $b->jumlah_saat_ini),
                'stok_minimum' => $baris->max(fn ($b) => (float) $b->stok_minimum),
                'tanggal_kadaluarsa' => $baris->pluck('tanggal_kadaluarsa')->filter()->min(),
                'opname_terakhir_pada' => $baris->pluck('opname_terakhir_pada')->filter()->max(),
                'perlu_diperiksa' => true,
                'updated_at' => now(),
            ]);
        }
    }
};
