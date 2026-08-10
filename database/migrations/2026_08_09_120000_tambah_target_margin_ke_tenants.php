<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Target margin usaha, dipakai menyusun SARAN harga jual.
     *
     * Di tabel tenants, bukan tabel setelan tersendiri: ini satu angka untuk satu usaha, dan
     * tabel setelan berpasangan kunci-nilai untuk satu baris adalah biaya yang dibayar di
     * muka untuk keluwesan yang belum tentu dibutuhkan. Kalau nanti setelan usaha bertambah
     * banyak, memindahkannya adalah satu migrasi.
     *
     * BAWAANNYA 30%, bukan null. Saran harga yang tidak pernah muncul sampai pemilik
     * menemukan dan mengisi satu kotak setelan sama saja dengan tidak ada — dan 30% adalah
     * angka yang cukup umum di warung untuk jadi titik awal yang tidak menyesatkan. Yang
     * penting: angkanya SELALU terlihat di sebelah sarannya, jadi pemilik tahu saran itu
     * datang dari mana dan bisa mengubahnya.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->decimal('target_margin', 5, 2)->default(30)->after('business_type');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('target_margin');
        });
    }
};
