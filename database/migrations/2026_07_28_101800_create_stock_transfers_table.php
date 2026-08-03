<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Perpindahan stok antar outlet, dipakai pada model stok terpusat (gudang pusat).
     */
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_asal_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUuid('outlet_tujuan_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('nomor_transfer');
            $table->date('tanggal');
            $table->string('status')->default('draft')->index();
            $table->text('catatan')->nullable();
            $table->foreignUuid('dikirim_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('diterima_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diterima_pada')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'nomor_transfer']);
            $table->index(['tenant_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
