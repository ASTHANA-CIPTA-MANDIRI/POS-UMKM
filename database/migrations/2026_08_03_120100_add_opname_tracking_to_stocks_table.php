<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak opname per baris stok.
     *
     * `opname_terakhir_pada` menjawab pertanyaan yang tidak bisa dijawab oleh saldo:
     * "angka ini terakhir dicocokkan dengan barang fisik kapan?". Saldo yang tidak pernah
     * dihitung ulang selama enam bulan kelihatan sama persis dengan saldo yang dihitung
     * tadi pagi.
     *
     * `perlu_diperiksa` adalah penanda manual — dipasang saat ada yang mencurigai
     * angkanya (mis. stok minus setelah sinkronisasi offline) supaya barangnya masuk
     * daftar hitung berikutnya tanpa perlu diingat orang.
     */
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dateTime('opname_terakhir_pada')->nullable()->after('tanggal_kadaluarsa');
            $table->boolean('perlu_diperiksa')->default(false)->after('opname_terakhir_pada');
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn(['opname_terakhir_pada', 'perlu_diperiksa']);
        });
    }
};
