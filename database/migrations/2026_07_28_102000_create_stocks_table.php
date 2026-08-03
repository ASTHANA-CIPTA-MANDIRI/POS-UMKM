<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stok dicatat PER OUTLET (tidak digabung antar outlet). Satu baris mengacu ke
     * product_id ATAU raw_material_id.
     *
     * Catatan keterbatasan: satu baris per produk per outlet berarti tanggal
     * kadaluarsa tersimpan sebagai satu nilai, bukan per batch penerimaan. FEFO
     * penuh (First Expired First Out) nanti butuh tabel batch terpisah.
     */
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('raw_material_id')->nullable()->constrained()->cascadeOnDelete();

            $table->decimal('jumlah_saat_ini', 15, 3)->default(0);
            $table->decimal('stok_minimum', 15, 3)->default(0);
            $table->date('tanggal_kadaluarsa')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'product_id']);
            $table->unique(['outlet_id', 'raw_material_id']);
            $table->index(['tenant_id', 'outlet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
