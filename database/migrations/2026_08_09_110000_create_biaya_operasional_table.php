<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Biaya operasional yang BERULANG: sewa, listrik, gaji, gas, langganan internet.
     *
     * KENAPA TABEL SENDIRI, padahal sudah ada `cash_movements` untuk uang keluar. Yang ini
     * BUKAN transaksi — ini ANGKA PERENCANAAN. Sewa Rp 1.500.000/bulan tetap harus ikut
     * hitungan margin pada hari sewanya belum dibayar, karena warungnya memang sedang memakai
     * tempat itu hari ini. Menggabungkannya dengan pengeluaran nyata membuat margin melonjak
     * di tanggal 1 dan anjlok di tanggal 5, dan tidak ada satu pun angka yang bisa dipakai
     * untuk memutuskan harga.
     *
     * Konsekuensi yang harus diketahui: satu biaya bisa muncul DUA KALI dalam pandangan
     * pemilik — sekali di sini sebagai rencana, sekali di kas keluar saat benar-benar
     * dibayar. Itu memang dua pertanyaan yang berbeda ("berapa beban warung per hari" vs
     * "berapa uang yang keluar hari ini"), dan layarnya wajib mengatakan itu.
     */
    public function up(): void
    {
        Schema::create('biaya_operasional', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            /*
             * NULL berarti biaya seluruh warung, bukan biaya satu cabang.
             *
             * Sewa dan listrik menempel pada cabang; gaji pemilik dan langganan internet
             * kantor tidak. Memaksa semuanya punya cabang membuat warung satu cabang harus
             * memilih hal yang tidak berarti apa-apa baginya.
             */
            $table->foreignUuid('outlet_id')->nullable()->constrained()->nullOnDelete();

            $table->string('nama');
            $table->decimal('nominal', 15, 2);
            $table->string('periode')->default('bulanan');

            /*
             * Rentang berlakunya. `mulai` wajib, `selesai` boleh kosong (masih berjalan).
             *
             * Tanpa rentang, biaya yang sudah berhenti — sewa lapak lama, gaji karyawan yang
             * sudah keluar — ikut membebani hitungan selamanya, dan margin warung terlihat
             * lebih buruk daripada kenyataannya sampai ada yang sadar menghapusnya. Menghapus
             * bukan jawaban yang benar: riwayat bulan lalu tetap butuh angka itu.
             */
            $table->date('mulai');
            $table->date('selesai')->nullable();

            $table->text('catatan')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'mulai']);
            $table->index(['tenant_id', 'outlet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biaya_operasional');
    }
};
