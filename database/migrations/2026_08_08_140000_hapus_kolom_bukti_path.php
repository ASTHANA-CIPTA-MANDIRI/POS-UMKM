<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membuang `purchase_orders.bukti_path` — satu foto per nota berakhir di sini.
 *
 * MEMERIKSA DULU, dan menolak jalan kalau salinannya belum lengkap. Kolom yang dibuang
 * tidak bisa dikembalikan isinya dari mana pun; kalau ada satu nota yang path-nya belum
 * punya baris `lampiran`, jejak bahwa nota itu pernah berfoto hilang selamanya. Migrasi
 * yang menghapus tanpa memeriksa adalah migrasi yang hanya benar pada mesin tempat ia
 * ditulis.
 *
 * Pemeriksaannya menyebut NOMOR NOTA yang bermasalah, bukan cuma "tidak cocok" — yang
 * membaca galat ini sedang berada di tengah deploy dan butuh tahu baris mana yang harus
 * diselamatkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('purchase_orders', 'bukti_path')) {
            return;
        }

        // TANPA global scope: nota yang soft-deleted juga membawa buktinya, dan nota batal
        // justru sering berarti barang dikembalikan — struk itu bukti pengembaliannya.
        $tertinggal = DB::table('purchase_orders')
            ->whereNotNull('bukti_path')
            ->where('bukti_path', '!=', '')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('lampiran')
                    ->whereColumn('lampiran.lampirable_id', 'purchase_orders.id')
                    ->where('lampiran.lampirable_type', 'purchase_order')
                    ->whereColumn('lampiran.path', 'purchase_orders.bukti_path');
            })
            ->pluck('nomor_po')
            ->all();

        if ($tertinggal !== []) {
            throw new RuntimeException(
                'Kolom bukti_path TIDAK dibuang: '.count($tertinggal).' nota masih punya path '
                .'yang belum tersalin ke tabel lampiran ('.implode(', ', array_slice($tertinggal, 0, 10))
                .(count($tertinggal) > 10 ? ', …' : '').'). Jalankan ulang migrasi '
                .'2026_08_08_091000_pindahkan_bukti_path_ke_lampiran lebih dulu — ia idempoten.',
            );
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('bukti_path');
        });
    }

    /**
     * Mengembalikan kolomnya BESERTA ISINYA, bukan kolom kosong.
     *
     * `migrate:rollback` yang menghasilkan kolom kosong sama saja dengan tidak bisa
     * di-rollback: aplikasi versi lama akan menyimpulkan tidak ada satu pun nota yang
     * berfoto, lalu menawarkan "pasang foto" untuk nota yang sebenarnya sudah punya.
     */
    public function down(): void
    {
        if (Schema::hasColumn('purchase_orders', 'bukti_path')) {
            return;
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('bukti_path')->nullable()->after('catatan');
        });

        // Lampiran ber-urutan terkecil per nota: itulah yang dulu ada di kolomnya.
        $pertama = DB::table('lampiran')
            ->where('lampirable_type', 'purchase_order')
            ->orderBy('lampirable_id')
            ->orderBy('urutan')
            ->orderBy('created_at')
            ->get(['lampirable_id', 'path'])
            ->unique('lampirable_id');

        foreach ($pertama as $l) {
            DB::table('purchase_orders')->where('id', $l->lampirable_id)
                ->update(['bukti_path' => $l->path]);
        }
    }
};
