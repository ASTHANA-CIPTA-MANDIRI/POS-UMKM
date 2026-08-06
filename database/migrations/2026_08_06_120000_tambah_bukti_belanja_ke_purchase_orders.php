<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATU kolom: foto kwitansi/struk belanja yang menyertai sebuah nota.
 *
 * Kenapa kolom, bukan tabel lampiran: SATU berkas per nota, tegas — bukan "mungkin nanti
 * banyak". Nota grosir tiga lembar memang tidak sepenuhnya terpotret, dan itu diterima,
 * karena rincian barangnya sudah ada DI DALAM nota digitalnya. Fotonya penguat, bukan
 * sumber. Tabel lampiran akan menuntut layar pengelola lampiran, urutan, dan penghapusan
 * per berkas — semuanya untuk sesuatu yang dibuka pemiliknya sekali saat ada selisih.
 *
 * Nullable, dan akan tetap nullable selamanya: warteg yang belanja di pasar pagi tidak
 * dapat struk sama sekali, sedangkan kelontong yang kulakan ke grosir selalu dapat.
 * Mewajibkannya berarti separuh pengguna berhenti mencatat belanja — dan nota yang tidak
 * dicatat merugikan jauh lebih banyak daripada nota tanpa foto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $tabel) {
            $tabel->string('bukti_path')->nullable()->after('catatan');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $tabel) {
            $tabel->dropColumn('bukti_path');
        });
    }
};
