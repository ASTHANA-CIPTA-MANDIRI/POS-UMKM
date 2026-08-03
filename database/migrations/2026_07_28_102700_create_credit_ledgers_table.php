<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buku utang/piutang pelanggan (kasbon) — kebutuhan khas warteg & kelontong.
     * Dicatat setelah struk tercetak, terpisah dari proses bayar itu sendiri.
     */
    public function up(): void
    {
        Schema::create('credit_ledgers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();

            // NULL bila kasbon dicatat manual tanpa transaksi kasir.
            $table->foreignUuid('transaction_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('jumlah_utang', 15, 2);
            $table->decimal('jumlah_dibayar', 15, 2)->default(0);
            $table->string('status')->default('belum_lunas')->index();
            $table->date('tanggal_jatuh_tempo')->nullable();
            $table->timestamp('dilunasi_pada')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();

            // Catatan piutang tidak boleh hilang permanen (prinsip 5 ERD) —
            // model CreditLedger memakai SoftDeletes.
            $table->softDeletes();

            $table->index(['tenant_id', 'customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledgers');
    }
};
