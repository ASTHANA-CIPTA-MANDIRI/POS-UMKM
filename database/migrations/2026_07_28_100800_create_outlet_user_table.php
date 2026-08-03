<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahan di luar ERD: ERD hanya menyediakan satu kolom outlet_id di USER_STAFF,
     * sehingga tidak bisa merepresentasikan Area/Regional Manager yang di-assign ke
     * SEKELOMPOK outlet (bagian 3.2.F). Pivot ini menutup kebutuhan tersebut.
     * Role selain regional_manager tetap memakai users.outlet_id.
     */
    public function up(): void
    {
        Schema::create('outlet_user', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['user_id', 'outlet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_user');
    }
};
