<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahan di luar ERD: ERD hanya menyebut PURCHASE_ORDER tanpa tabel item,
     * padahal PO harus merinci barang yang dipesan. Satu baris mengacu ke
     * product_id ATAU raw_material_id (tepat salah satu, divalidasi di aplikasi).
     */
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('raw_material_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('qty', 15, 3);
            $table->decimal('qty_diterima', 15, 3)->default(0);
            $table->decimal('harga_satuan', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->timestamps();

            $table->index('purchase_order_id', 'po_items_po_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
