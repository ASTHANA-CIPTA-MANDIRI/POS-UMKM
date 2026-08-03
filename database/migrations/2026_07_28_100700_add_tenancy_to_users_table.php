<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom tenancy dipisah dari migrasi users karena tabel tenants/outlets/devices
     * baru dibuat sesudahnya, sehingga foreign key tidak bisa didefinisikan di sana.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // NULL = Super Admin (pemilik platform), bukan milik tenant mana pun.
            $table->foreignUuid('tenant_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();

            // NULL untuk Owner (akses semua outlet) & Regional Manager (pakai pivot
            // outlet_user); WAJIB diisi untuk Manager Outlet/Kasir/Dapur yang
            // dikunci ke tepat 1 outlet.
            $table->foreignUuid('outlet_id')->nullable()->after('tenant_id')
                ->constrained()->nullOnDelete();

            // Device binding: dipakai untuk memvalidasi bahwa staff login dari
            // perangkat outlet yang benar (hard block, bukan warning).
            $table->foreignUuid('device_id_terikat')->nullable()->after('outlet_id')
                ->constrained('devices')->nullOnDelete();

            $table->index(['tenant_id', 'outlet_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['outlet_id']);
            $table->dropForeign(['device_id_terikat']);
            $table->dropIndex(['tenant_id', 'outlet_id', 'role']);
            $table->dropColumn(['tenant_id', 'outlet_id', 'device_id_terikat']);
        });
    }
};
