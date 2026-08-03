<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pembayaran tagihan langganan platform (bukan pembayaran transaksi kasir —
     * itu ada di tabel transaction_payments).
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('metode');
            $table->decimal('jumlah', 15, 2);
            $table->timestamp('tanggal_bayar');

            // Alur 2.1: transfer manual + upload bukti, atau otomatis via gateway.
            $table->string('referensi_gateway')->nullable();
            $table->string('bukti_transfer_path')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'tanggal_bayar']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
