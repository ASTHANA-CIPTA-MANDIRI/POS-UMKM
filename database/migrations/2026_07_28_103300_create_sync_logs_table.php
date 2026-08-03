<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak setiap upaya sinkronisasi dari perangkat. Dipakai untuk menjawab
     * pertanyaan operasional "transaksi saya sudah masuk belum?" dan untuk
     * menyelidiki perangkat yang lama tidak sinkron.
     */
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('jumlah_dikirim')->default(0);
            $table->unsignedInteger('jumlah_dibuat')->default(0);

            // Transaksi yang sudah ada sebelumnya — indikator retry, bukan error.
            $table->unsignedInteger('jumlah_duplikat')->default(0);

            $table->unsignedInteger('jumlah_gagal')->default(0);
            $table->json('detail_gagal')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'outlet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
