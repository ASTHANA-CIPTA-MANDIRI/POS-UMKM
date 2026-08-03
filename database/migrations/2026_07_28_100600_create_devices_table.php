<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('device_type');
            $table->string('serial_number')->unique();
            $table->string('ownership')->default('milik_merchant');
            $table->string('status')->default('aktif')->index();

            // Hanya terisi untuk ownership = milik_platform (paket sewa +device).
            $table->decimal('deposit_amount', 15, 2)->nullable();

            // ERD memakai tipe geopoint; dipecah jadi lat/lng decimal agar tidak
            // bergantung pada tipe spasial MySQL. Ini LOKASI TERAKHIR SAAT ONLINE,
            // bukan pelacakan real-time (lihat klausul privasi kontrak sewa).
            $table->decimal('last_known_lat', 10, 7)->nullable();
            $table->decimal('last_known_lng', 10, 7)->nullable();
            $table->timestamp('last_seen_at')->nullable();

            // Printer thermal tidak punya GPS/koneksi sendiri, jadi tidak bisa dilacak.
            $table->boolean('mendukung_pelacakan')->default(false);

            // Tiap outlet didaftarkan 2 perangkat (utama + cadangan) sebagai
            // pencegahan paling simpel saat perangkat utama rusak (bagian 3.2.E).
            $table->boolean('is_perangkat_utama')->default(true);

            $table->timestamp('mdm_locked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'outlet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
