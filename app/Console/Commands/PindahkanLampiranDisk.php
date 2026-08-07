<?php

namespace App\Console\Commands;

use App\Actions\Purchase\SimpanBuktiBelanjaAction;
use App\Models\Pembelian\PurchaseOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Memindahkan berkas bukti belanja dari disk `public` (disajikan statis oleh web server)
 * ke disk `lampiran` (hanya bisa dibuka lewat rute berpenjaga).
 *
 * KENAPA PERINTAH, BUKAN MIGRASI. Yang dipindah adalah BERKAS, bukan baris tabel: kolom
 * `bukti_path` sama sekali tidak berubah nilainya. Migrasi yang menyentuh berkas tidak bisa
 * dijalankan kering, tidak bisa diulang dengan aman, dan `migrate:rollback` tidak akan
 * pernah bisa mengembalikan berkas yang sudah dihapus. Perintah bisa dijalankan berulang,
 * dan itu yang dibutuhkan: kalau setengah jalan mati (disk penuh), jalankan lagi.
 *
 * EMPAT ATURAN, dan tiga di antaranya lahir dari kerugian nyata di proyek ini:
 *
 * 1. **KERING sebagai bawaan.** Tanpa `--tulis` perintah ini TIDAK menulis dan TIDAK
 *    menghapus apa pun — ia hanya mendaftar. Perintah yang langsung memindah berkas begitu
 *    ditekan Enter tidak memberi kesempatan siapa pun membaca daftarnya lebih dulu.
 *
 * 2. **HANYA awalan `bukti-belanja/`, dan itu ADA DI KUERINYA** — bukan disaring belakangan.
 *    Aturan keras nomor 1 CLAUDE.md: `storage/app/public/produk/` tidak boleh disentuh,
 *    karena gambar pengguna pernah hilang permanen dari situ. Berkas di luar awalan itu
 *    (`produk/`, `pratinjau/`) tidak pernah masuk ke daftar iterasinya sama sekali, jadi
 *    tidak ada satu pun jalur di berkas ini yang bisa menghapusnya walau penyaringnya kelak
 *    salah disunting.
 *
 * 3. **Verifikasi UKURAN dan MD5 sebelum sumbernya dihapus.** Salin yang "berhasil" menurut
 *    nilai kembalian put() masih bisa terpotong (disk penuh di byte terakhir). Beda satu
 *    byte pun: sumbernya TIDAK dihapus, notanya dicatat, dan perintahnya keluar dengan kode
 *    bukan nol — bukti belanja tidak punya salinan kedua.
 *
 * 4. **Termasuk nota yang SOFT-DELETED** (`withoutGlobalScopes()`, yang juga melepas
 *    SoftDeletingScope — di sini justru itu yang kita mau). Nota yang terhapus lunak tetap
 *    menunjuk ke berkasnya, dan berkas yang tertinggal di folder publik tetap bisa dibuka
 *    siapa pun yang punya URL-nya. Melewatkannya berarti pemindahannya bocor di tempat yang
 *    tidak terlihat dari layar mana pun. Sekaligus lintas tenant: perintah artisan berjalan
 *    tanpa TenantContext, dan tanpa withoutGlobalScopes() hasilnya bergantung pada kebetulan.
 */
class PindahkanLampiranDisk extends Command
{
    protected $signature = 'lampiran:pindahkan-disk
        {--kering : Hanya mendaftar, tanpa menulis apa pun (ini BAWAANNYA)}
        {--tulis : Benar-benar memindahkan berkasnya}';

    protected $description = 'Memindahkan bukti belanja dari disk public ke disk lampiran (bawaan: kering)';

    public function handle(): int
    {
        $awalan = SimpanBuktiBelanjaAction::FOLDER.'/';

        /*
         * Kering menang kalau keduanya diberikan. Perintah yang menebak maksud orang saat
         * perintahnya bertentangan adalah perintah yang menghapus berkas karena salah tebak.
         */
        $kering = $this->option('kering') || ! $this->option('tulis');

        if ($this->option('kering') && $this->option('tulis')) {
            $this->warn('--kering dan --tulis diberikan bersama; yang dipakai --kering.');
        }

        $sumber = Storage::disk('public');
        $tujuan = Storage::disk(SimpanBuktiBelanjaAction::DISK);

        $this->line($kering
            ? 'JALAN KERING — tidak ada berkas yang ditulis maupun dihapus.'
            : 'MEMINDAHKAN berkas: public -> '.SimpanBuktiBelanjaAction::DISK.'.');

        $notaSemua = PurchaseOrder::withoutGlobalScopes()
            ->whereNotNull('bukti_path')
            ->where('bukti_path', '!=', '')
            // Awalannya bagian dari kueri, bukan saringan belakangan. Lihat aturan 2.
            ->where('bukti_path', 'like', $awalan.'%')
            ->orderBy('created_at')
            ->get(['id', 'tenant_id', 'nomor_po', 'bukti_path']);

        $dipindah = 0;
        $sudah = 0;
        $hilang = 0;
        $gagal = [];

        foreach ($notaSemua as $nota) {
            $path = (string) $nota->bukti_path;

            /*
             * Jaring kedua atas hal yang sama, dan ia memang tidak akan pernah menyala:
             * kuerinya sudah menyaring. Ia ada supaya penyuntingan kelak yang melonggarkan
             * kuerinya tidak langsung berarti perintah ini boleh menghapus `produk/`.
             */
            if (! str_starts_with($path, $awalan)) {
                $gagal[] = [$nota->nomor_po, $path, 'di luar awalan '.$awalan.' — DILEWATI'];

                continue;
            }

            $adaDiSumber = $sumber->exists($path);
            $adaDiTujuan = $tujuan->exists($path);

            if (! $adaDiSumber) {
                // Sudah pindah pada jalan sebelumnya, atau memang tidak pernah ada.
                if ($adaDiTujuan) {
                    $sudah++;
                    $this->line("  sudah di tujuan: {$path}");
                } else {
                    $hilang++;
                    $this->warn("  berkasnya tidak ada di kedua disk: {$path} (nota {$nota->nomor_po})");
                }

                continue;
            }

            $this->line("  akan dipindah: {$path} (nota {$nota->nomor_po}, ".$this->ukuran($sumber->size($path)).')');

            if ($kering) {
                $dipindah++;

                continue;
            }

            $isi = (string) $sumber->get($path);

            if ($tujuan->put($path, $isi) !== true) {
                $gagal[] = [$nota->nomor_po, $path, 'gagal menulis ke disk tujuan'];

                continue;
            }

            // Ukuran DAN md5, dibaca ulang dari disk tujuan — bukan dari variabel yang baru
            // saja kita tulis. Yang mau dibuktikan adalah apa yang benar-benar mendarat.
            $ukuranSumber = (int) $sumber->size($path);
            $ukuranTujuan = (int) $tujuan->size($path);
            $md5Sumber = md5($isi);
            $md5Tujuan = md5((string) $tujuan->get($path));

            if ($ukuranSumber !== $ukuranTujuan || $md5Sumber !== $md5Tujuan) {
                $gagal[] = [
                    $nota->nomor_po,
                    $path,
                    "salinannya BEDA (ukuran {$ukuranSumber} vs {$ukuranTujuan}, md5 {$md5Sumber} vs {$md5Tujuan}) — sumber tidak dihapus",
                ];

                continue;
            }

            $sumber->delete($path);
            $dipindah++;
        }

        $this->newLine();
        $this->line('Nota berbukti yang diperiksa : '.$notaSemua->count());
        $this->line($kering
            ? 'Akan dipindah                : '.$dipindah
            : 'Dipindah                     : '.$dipindah);
        $this->line('Sudah ada di disk lampiran   : '.$sudah);
        $this->line('Berkasnya tidak ada          : '.$hilang);

        if ($gagal !== []) {
            $this->newLine();
            $this->error('GAGAL '.count($gagal).' berkas — sumbernya SENGAJA tidak dihapus:');

            foreach ($gagal as [$nomor, $path, $sebab]) {
                $this->error("  {$nomor} {$path}: {$sebab}");
            }

            return self::FAILURE;
        }

        if ($kering) {
            $this->newLine();
            $this->line('Belum ada yang berubah. Jalankan ulang dengan --tulis untuk benar-benar memindahkannya.');
        }

        return self::SUCCESS;
    }

    /** Ukuran yang dibaca orang; angka byte mentah tidak menolong siapa pun membaca daftar. */
    private function ukuran(int $byte): string
    {
        return $byte >= 1024 * 1024
            ? round($byte / (1024 * 1024), 1).' MB'
            : max(1, (int) round($byte / 1024)).' KB';
    }
}
