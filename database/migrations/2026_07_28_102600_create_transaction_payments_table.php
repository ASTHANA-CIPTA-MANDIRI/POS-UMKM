<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu transaksi bisa punya beberapa baris pembayaran (split payment:
     * cash + QRIS, dsb).
     */
    public function up(): void
    {
        Schema::create('transaction_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('metode');
            $table->decimal('jumlah', 15, 2);

            // Uang diterima & kembalian, khusus metode cash.
            $table->decimal('jumlah_diterima', 15, 2)->nullable();
            $table->decimal('kembalian', 15, 2)->nullable();

            $table->string('referensi')->nullable();
            $table->timestamps();

            $table->index('transaction_id', 'trx_payments_trx_idx');
            $table->index(['tenant_id', 'metode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_payments');
    }
};
