<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Memindahkan satu-foto-per-nota (`purchase_orders.bukti_path`) ke tabel `lampiran`.
 *
 * Memakai kueri MENTAH, bukan model Eloquent: migrasi hidup selamanya di riwayat, sedangkan
 * model berubah bentuk. Migrasi yang memanggil model akan pecah pada mesin baru yang
 * menjalankan seluruh riwayat dari nol — dan pecahnya di tengah, sesudah sebagian data
 * berpindah.
 *
 * Kolom `bukti_path` SENGAJA belum dihapus di sini. Satu migrasi mengubah satu hal: yang ini
 * menyalin, yang berikutnya membuang — dan yang membuang memeriksa dulu bahwa salinannya
 * lengkap. Menggabungkan keduanya berarti tidak ada satu titik pun yang bisa diperiksa
 * sebelum kolomnya lenyap.
 */
return new class extends Migration
{
    public function up(): void
    {
        // TERMASUK nota yang soft-deleted: buktinya tetap data, dan nota batal justru sering
        // berarti barangnya dikembalikan — struk itu satu-satunya bukti pengembaliannya.
        $nota = DB::table('purchase_orders')
            ->whereNotNull('bukti_path')
            ->where('bukti_path', '!=', '')
            ->get(['id', 'tenant_id', 'bukti_path', 'created_at']);

        foreach ($nota as $satu) {
            $sudahAda = DB::table('lampiran')
                ->where('lampirable_type', 'purchase_order')
                ->where('lampirable_id', $satu->id)
                ->where('path', $satu->bukti_path)
                ->exists();

            // Bisa dijalankan ulang tanpa menggandakan.
            if ($sudahAda) {
                continue;
            }

            /*
             * Berkasnya boleh TIDAK ADA, dan barisnya tetap dibuat.
             *
             * Path adalah data; melewatkannya berarti menghapus jejak bahwa nota itu pernah
             * berfoto. Tampilannya tidak berubah karena layar memeriksa keberadaan berkas
             * (Lampiran::ada()), persis seperti punyaBukti() sebelumnya.
             */
            $ada = Storage::disk('lampiran')->exists($satu->bukti_path);

            DB::table('lampiran')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $satu->tenant_id,
                'lampirable_type' => 'purchase_order',
                'lampirable_id' => $satu->id,
                'path' => $satu->bukti_path,
                'nama_asli' => null,
                'mime' => $ada
                    ? (Storage::disk('lampiran')->mimeType($satu->bukti_path) ?: 'application/octet-stream')
                    : 'application/octet-stream',
                'ukuran' => $ada ? (int) Storage::disk('lampiran')->size($satu->bukti_path) : 0,
                'urutan' => 1,
                'diunggah_oleh' => null,
                'created_at' => $satu->created_at,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('lampiran')->where('lampirable_type', 'purchase_order')->delete();
    }
};
