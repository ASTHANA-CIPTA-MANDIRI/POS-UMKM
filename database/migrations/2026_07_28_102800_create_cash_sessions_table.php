<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 1 sesi = 1 shift kasir (buka–tutup laci kas).
     */
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('staff_id')->constrained('users')->cascadeOnDelete();

            $table->timestamp('dibuka_pada');
            $table->timestamp('ditutup_pada')->nullable();

            $table->decimal('modal_awal', 15, 2)->default(0);

            // Diisi saat tutup kasir: hitungan sistem vs hitungan fisik, dan selisihnya.
            $table->decimal('kas_akhir_sistem', 15, 2)->nullable();
            $table->decimal('kas_akhir_fisik', 15, 2)->nullable();
            $table->decimal('selisih', 15, 2)->nullable();

            $table->string('status')->default('terbuka')->index();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'outlet_id', 'dibuka_pada']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};
