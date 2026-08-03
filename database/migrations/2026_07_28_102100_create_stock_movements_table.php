<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kartu stok / mutasi stok. Setiap perubahan jumlah_saat_ini di tabel stocks
     * wajib meninggalkan satu baris di sini supaya bisa diaudit.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_id')->constrained()->cascadeOnDelete();

            $table->string('tipe')->index();
            $table->decimal('jumlah', 15, 3);
            $table->decimal('saldo_sesudah', 15, 3)->nullable();

            // ERD menyebut tiga kolom referensi terpisah (purchase_order_id /
            // transaction_id / stock_transfer_id). Dipakai relasi polymorphic supaya
            // sumber mutasi baru bisa ditambah tanpa mengubah skema, dan agar mutasi
            // opname (yang tidak punya referensi) tetap wajar. Konsekuensinya tidak
            // ada foreign key di level database untuk kolom ini.
            $table->nullableUuidMorphs('referensi');

            $table->foreignUuid('oleh_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('catatan')->nullable();
            $table->timestamps();

            // Prinsip 4 ERD: composite index untuk performa query laporan.
            $table->index(['tenant_id', 'outlet_id', 'created_at'], 'stock_mv_tenant_outlet_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
