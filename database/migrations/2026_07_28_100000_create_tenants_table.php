<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_name');
            $table->string('business_type')->index();
            $table->string('owner_name');
            $table->string('owner_phone');
            $table->string('status')->default('trial')->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();

            // Prinsip 5 ERD: merchant yang berhenti langganan di-soft-delete dulu,
            // baru dihapus permanen setelah masa retensi oleh job terjadwal.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
