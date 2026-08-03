<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained()->cascadeOnDelete();

            // tenant_id didenormalisasi dari subscription supaya tabel ini tetap
            // memenuhi prinsip 1 ERD (semua tabel transaksional punya tenant_id).
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('nomor_invoice')->unique();
            $table->date('periode_mulai');
            $table->date('periode_selesai');
            $table->decimal('jumlah_tagihan', 15, 2);
            $table->string('status')->default('belum_bayar')->index();
            $table->date('jatuh_tempo');
            $table->timestamp('dibayar_pada')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
