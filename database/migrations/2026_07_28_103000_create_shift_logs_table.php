<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jadwal & realisasi shift kerja per staff.
     */
    public function up(): void
    {
        Schema::create('shift_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            $table->timestamp('jadwal_mulai');
            $table->timestamp('jadwal_selesai')->nullable();
            $table->timestamp('mulai_aktual')->nullable();
            $table->timestamp('selesai_aktual')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'outlet_id', 'jadwal_mulai']);
            $table->index(['user_id', 'jadwal_mulai']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_logs');
    }
};
