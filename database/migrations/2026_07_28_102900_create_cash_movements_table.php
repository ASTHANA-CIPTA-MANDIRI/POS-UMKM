<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cash_session_id')->constrained()->cascadeOnDelete();

            // penjualan / pengeluaran_petty_cash / setoran / lainnya
            $table->string('tipe')->index();

            $table->decimal('jumlah', 15, 2);
            $table->text('catatan')->nullable();
            $table->foreignUuid('oleh_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('cash_session_id', 'cash_mv_session_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
