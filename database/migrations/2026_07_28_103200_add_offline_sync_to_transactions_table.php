<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dukungan Mode Offline-First (pembeda #1 dokumen fitur): kasir tetap bisa
     * transaksi saat internet mati, lalu auto-sync ketika online kembali.
     *
     * Kunci idempotensi TIDAK memakai kolom baru: primary key transaksi adalah UUID
     * yang dibuat di perangkat saat transaksi terjadi. Batch yang sama dikirim
     * berulang (retry karena koneksi putus di tengah) akan dikenali dari id itu,
     * sehingga transaksi tidak pernah dobel.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('origin')->default('online')->after('status');

            // Waktu menurut jam perangkat saat transaksi dibuat offline. Bisa berbeda
            // dari created_at (waktu server saat baris masuk), dan itu memang wajar —
            // laporan omzet harian harus memakai waktu_transaksi, bukan created_at.
            $table->timestamp('dibuat_offline_pada')->nullable()->after('origin');

            $table->timestamp('disinkronkan_pada')->nullable()->after('dibuat_offline_pada');

            $table->index(['tenant_id', 'origin']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'origin']);
            $table->dropColumn(['origin', 'dibuat_offline_pada', 'disinkronkan_pada']);
        });
    }
};
