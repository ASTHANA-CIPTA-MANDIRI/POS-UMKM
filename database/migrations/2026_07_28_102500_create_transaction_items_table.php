<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained()->nullOnDelete();

            // Snapshot nama & harga saat transaksi terjadi, supaya struk lama tetap
            // akurat walau produk kemudian di-rename, diubah harganya, atau dihapus.
            $table->string('nama_produk');

            $table->decimal('qty', 15, 3);
            $table->decimal('harga_satuan', 15, 2);
            $table->decimal('diskon', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2);

            // Modifier FnB: pedas/tidak, nasi banyak/sedikit, dsb.
            $table->string('catatan_modifier')->nullable();

            $table->timestamps();

            $table->index('transaction_id', 'trx_items_trx_idx');
            $table->index(['tenant_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_items');
    }
};
