<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();

            // NULL untuk Mode A (langsung) yang tidak lewat bill.
            $table->foreignUuid('bill_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained('devices')->nullOnDelete();

            $table->string('nomor_transaksi');
            $table->string('mode');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('diskon', 15, 2)->default(0);
            $table->decimal('pajak', 15, 2)->default(0);
            $table->decimal('service_charge', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->string('status')->default('lunas');
            $table->timestamp('waktu_transaksi');

            // Void wajib mengisi alasan + approval owner/supervisor.
            $table->text('alasan_void')->nullable();
            $table->foreignUuid('di_void_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('di_void_pada')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'nomor_transaksi']);

            // Prinsip 4 ERD: composite index untuk performa query laporan.
            $table->index(['tenant_id', 'outlet_id', 'created_at'], 'trx_tenant_outlet_created_idx');
            $table->index(['outlet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
