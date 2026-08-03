<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_asset_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->foreignUuid('oleh_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('catatan')->nullable();

            // Snapshot lokasi saat event dicatat — dipakai sebagai data pendukung
            // pada klaim kehilangan (klausul 7 kontrak sewa).
            $table->decimal('lat_saat_event', 10, 7)->nullable();
            $table->decimal('lng_saat_event', 10, 7)->nullable();

            $table->timestamps();

            $table->index(['device_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_asset_logs');
    }
};
