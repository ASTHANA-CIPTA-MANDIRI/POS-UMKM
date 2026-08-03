<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // Satu produk = satu kategori. Diagram ERD menggambarkan relasi
            // many-to-many, tapi tabel atribut ERD menetapkan kategori_id sebagai FK
            // tunggal; yang terakhir dipakai karena sejalan dengan dokumen fitur.
            $table->foreignUuid('kategori_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->string('nama_produk');
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('harga_default', 15, 2)->default(0);
            $table->decimal('harga_beli', 15, 2)->nullable();
            $table->string('satuan')->default('pcs');

            // Konversi satuan (1 dus = 12 pcs): harga mengikuti satuan yang dipilih
            // kasir, stok tetap dicatat dalam satuan dasar.
            $table->string('satuan_dasar')->nullable();
            $table->decimal('isi_per_satuan', 15, 3)->nullable();

            $table->string('gambar_path')->nullable();

            // Produk jasa (mis. laundry per kg) tidak perlu pencatatan stok.
            $table->boolean('lacak_stok')->default(true);
            $table->boolean('pantau_kadaluarsa')->default(false);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
            $table->unique(['tenant_id', 'sku']);
            $table->unique(['tenant_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
