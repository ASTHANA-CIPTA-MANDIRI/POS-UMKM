<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alasan penyesuaian stok, sebagai KODE (lihat App\Enums\AlasanOpname) — bukan teks
     * bebas.
     *
     * Kolom `catatan` yang sudah ada tetap dipakai untuk kalimat manusia. Yang tidak bisa
     * dilakukan dengan catatan bebas adalah menghitungnya: "berapa rupiah barang rusak
     * bulan ini" mustahil dijawab kalau kasir menulis "rusak", "pecah", "bocor kena air",
     * dan "rusak (lagi)" untuk hal yang sama. Selisih opname yang tidak bisa dikelompokkan
     * hanya menjadi angka yang membuat penasaran tanpa pernah bisa ditindaklanjuti.
     *
     * Nullable karena mutasi dari penjualan, pembelian, dan transfer tidak punya alasan —
     * tipenya sendiri sudah menjelaskan sebabnya.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            // Diindeks bersama tenant: laporan selisih selalu dibaca per merchant, dan
            // pengelompokan per alasan adalah gunanya kolom ini.
            $table->string('alasan')->nullable()->after('tipe');

            $table->index(['tenant_id', 'alasan'], 'stock_mv_tenant_alasan_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_mv_tenant_alasan_idx');
            $table->dropColumn('alasan');
        });
    }
};
