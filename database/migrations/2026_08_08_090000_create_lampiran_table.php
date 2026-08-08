<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lampiran (foto struk, kwitansi, PDF invoice) — POLIMORFIK sejak awal.
 *
 * Kenapa polimorfik padahal pemakainya baru satu: `docs/RENCANA.md` menaruh Kasbon dan
 * Karyawan sebagai pekerjaan WAJIB SEBELUM DEPLOY, dan keduanya menuntut bukti foto yang
 * sama persis (kwitansi kasbon, slip gaji bertanda tangan). Biayanya di atas tabel khusus
 * cuma dua kolom, dan `Relation::enforceMorphMap()` sudah aktif — jenis baru tinggal
 * menambahkan aliasnya. Yang SENGAJA tidak dibuat sekarang: aksi/layar generik untuk
 * keempat jenis. Abstraksi tanpa pemakai kedua adalah bentuk yang ditebak.
 *
 * Kolom `mime` diisi dari SNIFF ISI berkas, bukan dari yang dikirim klien, dan ia satu-
 * satunya sumber `Content-Type` saat menyajikan. Ekstensi yang dipercaya adalah cara
 * berkas HTML mengaku PDF lalu dijalankan di origin kita sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lampiran', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // uuidMorphs: id induknya UUID seperti seluruh tabel di aplikasi ini.
            $table->uuidMorphs('lampirable');

            $table->string('path');

            /*
             * Nama asli HANYA untuk ditampilkan dan jadi nama unduhan — tidak pernah dipakai
             * menyusun path. Nama dari klien bisa memuat "../" dan byte apa pun.
             */
            $table->string('nama_asli', 150)->nullable();

            $table->string('mime', 100);
            $table->unsignedInteger('ukuran');
            $table->unsignedTinyInteger('urutan')->default(1);

            $table->foreignUuid('diunggah_oleh')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /*
             * TIDAK unik pada (lampirable, urutan), dan itu keputusan.
             *
             * Dua perangkat yang mengunggah ke nota yang sama bisa menghasilkan urutan yang
             * sama; unique index di situ membuat unggahan kedua MELEMPAR dan struknya
             * hilang. Menolak struk yang sah lebih merugikan daripada dua lampiran berurutan
             * sama — polanya sama dengan keputusan baris `stocks`. Urutan tampilnya
             * dijadikan pasti oleh tiga kunci di kueri (urutan, created_at, id), bukan oleh
             * indeks.
             */
            $table->index(['lampirable_type', 'lampirable_id', 'urutan'], 'lampiran_induk_urutan_idx');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lampiran');
    }
};
