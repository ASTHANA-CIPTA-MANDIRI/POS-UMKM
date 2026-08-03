<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nama_paket');
            $table->string('slug')->unique();

            // NULL = unlimited (paket Enterprise).
            $table->unsignedInteger('limit_outlet')->nullable();
            $table->unsignedInteger('limit_user')->nullable();
            $table->unsignedInteger('limit_transaksi_bulanan')->nullable();

            $table->decimal('harga_bulanan', 15, 2)->default(0);
            $table->decimal('harga_bulanan_device', 15, 2)->nullable();
            $table->json('fitur_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
