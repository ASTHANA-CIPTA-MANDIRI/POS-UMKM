<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "1 tagihan berjalan" — dipakai Mode B (open bill) & Mode C (pesan/titip).
     * Mode A (transaksi langsung) membuat TRANSACTION tanpa BILL.
     */
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('mode');
            $table->string('status')->default('terbuka');

            // Nomor meja / nama pelanggan / kode titipan.
            $table->string('label');

            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('dibuka_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dibuka_pada');
            $table->timestamp('ditutup_pada')->nullable();

            // Mode C: estimasi selesai untuk laundry/titipan galon.
            $table->timestamp('estimasi_selesai')->nullable();

            $table->text('catatan')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Daftar bill terbuka per outlet adalah query terpanas di UI kasir.
            $table->index(['outlet_id', 'status']);
            $table->index(['tenant_id', 'outlet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
