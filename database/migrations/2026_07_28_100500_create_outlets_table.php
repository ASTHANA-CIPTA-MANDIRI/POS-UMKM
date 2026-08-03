<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('outlet_name');
            $table->string('address')->nullable();

            // Mode transaksi yang diaktifkan di outlet ini: [langsung, open_bill, pesan_antar].
            // Kasir memilih mode per-transaksi dari daftar ini (bagian 3.2.A dokumen fitur).
            $table->json('active_modes');

            $table->string('stock_model')->default('mandiri');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlets');
    }
};
