<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak PELUNASAN kasbon: tiap kali pelanggan menyetor, satu baris.
     *
     * KENAPA TABEL SENDIRI, padahal `credit_ledgers.jumlah_dibayar` sudah ada.
     *
     * Kolom itu satu ANGKA, bukan riwayat. Tanpa tabel ini, warung yang menerima cicilan
     * tiga kali cuma menyimpan hasil akhirnya, dan tiga hal berhenti bisa dijawab:
     *
     *   1. "Kapan saya bayar yang seratus ribu itu?" — pertanyaan pertama yang keluar dari
     *      mulut pelanggan saat ia merasa sudah membayar lebih banyak daripada yang tercatat.
     *      Buku kasbon kertas menjawabnya; aplikasi yang tidak bisa menjawabnya akan
     *      dikalahkan oleh buku itu.
     *   2. "Saya salah ketik 500.000, harusnya 50.000." Dengan agregat, satu-satunya jalan
     *      adalah menimpanya — dan sesudah ditimpa tidak ada yang tahu itu pernah terjadi.
     *      Dengan tabel ini, setoran yang salah DIBATALKAN dan pembatalannya berbekas.
     *   3. "Berapa yang masuk hari ini dari penagihan?" — tidak bisa dihitung sama sekali
     *      dari kolom agregat, karena tidak ada tanggalnya.
     *
     * `credit_ledgers.jumlah_dibayar` DIPERTAHANKAN sebagai angka turunan yang disimpan
     * (dihitung ulang dari SUM tabel ini di dalam transaksi yang sama). Bukan duplikasi yang
     * kelewat: layar daftar menyaring dan mengurutkan atas sisa utang, dan subkueri SUM di
     * setiap baris membuat daftar 300 pelanggan menjadi 301 kueri. Yang dijaga: tidak ada
     * satu pun jalur yang boleh menaikkan `jumlah_dibayar` tanpa membuat baris di sini —
     * itulah sebabnya seluruh perubahannya lewat CatatPelunasanAction.
     */
    public function up(): void
    {
        Schema::create('credit_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('credit_ledger_id')->constrained()->cascadeOnDelete();

            /*
             * Siapa yang menerima uangnya. nullOnDelete, BUKAN cascade: staf yang berhenti
             * bekerja lalu datanya dihapus tidak boleh membawa serta bukti bahwa uangnya
             * pernah masuk. Barisnya tetap, penerimanya jadi "—".
             */
            $table->foreignUuid('diterima_oleh')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('jumlah', 15, 2);

            /*
             * Waktu setornya TERPISAH dari created_at, dan itu bukan berlebihan: pemilik
             * sering baru mencatat malam hari setoran yang diterima siang, atau menyusulkan
             * catatan kemarin. created_at menjawab "kapan diketik", kolom ini menjawab
             * "kapan uangnya diterima" — dan yang kedua itulah yang dibandingkan pelanggan
             * dengan ingatannya.
             */
            $table->timestamp('dibayar_pada');

            $table->string('metode')->nullable();
            $table->text('catatan')->nullable();

            /*
             * Pembatalan setoran memakai soft delete, sesuai prinsip "jangan menghapus data
             * permanen". Baris yang dibatalkan tidak ikut dijumlah, tapi tetap terbaca
             * sebagai bukti bahwa pernah ada pencatatan yang keliru — dan itu justru yang
             * dicari orang saat angka kasbon tidak cocok dengan ingatannya.
             */
            $table->softDeletes();
            $table->timestamps();

            $table->index(['tenant_id', 'dibayar_pada']);
            $table->index('credit_ledger_id', 'credit_payments_ledger_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_payments');
    }
};
