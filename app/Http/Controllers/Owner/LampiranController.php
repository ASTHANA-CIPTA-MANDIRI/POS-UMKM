<?php

namespace App\Http\Controllers\Owner;

use App\Actions\Pembelian\SimpanBuktiBelanjaAction;
use App\Http\Controllers\Controller;
use App\Models\Pembelian\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Satu-satunya jalan membuka lampiran nota (foto kwitansi/struk belanja).
 *
 * KENAPA ADA. Sebelum ini berkasnya duduk di `storage/app/public/bukti-belanja/` dan
 * disajikan LANGSUNG oleh web server lewat symlink `public/storage` — tanpa pemeriksaan
 * login, tanpa tenant, tanpa outlet. Yang menahannya cuma nama berkas UUID yang tidak bisa
 * ditebak, dan penjaga semacam itu hanya bertahan selama URL-nya tidak pernah keluar dari
 * layar: satu tautan yang tersalin ke WhatsApp bocor SELAMANYA, tidak bisa dicabut, dan yang
 * bocor adalah harga beli beserta nama pemasok — dua hal yang paling tidak boleh dibaca
 * warung sebelah.
 *
 * PENJAGANYA BERLAPIS, dan tiap lapis menahan hal yang berbeda:
 *
 *  1. `auth` — tamu dialihkan ke halaman masuk, tidak pernah melihat satu byte pun isinya.
 *  2. `peran:owner,regional_manager,manager_outlet` — kasir yang sah TETAP tidak boleh:
 *     harga beli bukan wewenangnya, dan ia memegang uang laci.
 *  3. Route-model binding + TenantScope — nota tenant lain tidak pernah ditemukan.
 *  4. `canAccessOutlet()` — manager Cabang B tidak boleh membaca struk Cabang A walaupun
 *     satu tenant. Ini lapis yang paling mudah dilupakan, dan tanpanya tidak ada gejala
 *     apa pun: harga beli cabang lain terbaca lengkap tanpa satu pun pesan galat.
 *
 * SEMUA penolakan berupa 404, TERMASUK yang sebenarnya soal wewenang. 403 mengatakan
 * "berkasnya ada, tapi bukan milikmu" — dan konfirmasi bahwa sebuah nota ada di tenant lain
 * sudah kebocoran tersendiri: orang bisa menghitung jumlah nota orang lain hanya dari beda
 * 403 dan 404.
 *
 * Header cache WAJIB ada, dan itu bukan penghematan mikro. Sesudah pemindahan ini setiap
 * tampilan foto mengalir lewat PHP; tanpa ETag, tablet warung mengunduh ulang berkas 4 MB
 * setiap kali panel rincian dirender. `must-revalidate` + `max-age=0` sengaja dipilih
 * ketimbang max-age panjang: URL-nya TETAP SAMA saat fotonya diganti (penandanya `bukti`,
 * bukan nama berkasnya), jadi peramban harus selalu bertanya — jawabannya cuma 304 tanpa
 * badan, dan foto yang sudah diganti tidak pernah tampil basi.
 */
class LampiranController extends Controller
{
    /**
     * Hanya mime gambar ini yang boleh `inline`.
     *
     * Daftar putih, bukan "asal bukan HTML": berkas yang dirender di origin aplikasi sendiri
     * berarti skrip yang berjalan sebagai pengguna yang login. SVG SENGAJA tidak masuk
     * (SVG memuat <script>), dan apa pun di luar daftar ini turun menjadi `attachment` —
     * diunduh, tidak dirender. Validator unggahan sudah membatasi jpg/png/webp, tapi berkas
     * bisa datang dari salinan lama, dari migrasi, atau dari gelombang berikutnya (PDF).
     */
    private const MIME_INLINE = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __invoke(Request $request, PurchaseOrder $nota, string $penanda): Response
    {
        /*
         * Penandanya diperiksa DULU, dan nilainya cuma satu di gelombang ini.
         *
         * Bentuk rutenya sudah menyediakan tempat untuk banyak lampiran (gelombang 2), jadi
         * `bukti` di sini akan berdampingan dengan id lampiran tanpa mengubah bentuk URL —
         * URL yang sudah tersimpan di riwayat peramban pemilik tetap berlaku.
         */
        abort_unless($penanda === PurchaseOrder::PENANDA_BUKTI, 404);

        $pengguna = $request->user();

        // 404, bukan 403 — lihat catatan kelas. outlet_id tidak pernah null di tabel ini.
        abort_unless(
            $pengguna !== null && $pengguna->canAccessOutlet((string) $nota->outlet_id),
            404,
        );

        // Path yang menggantung (berkas terhapus di luar aplikasi, salinan database dibawa
        // ke mesin lain) berakhir 404, bukan 500: yang salah bukan permintaannya.
        abort_unless($nota->punyaBukti(), 404);

        $disk = Storage::disk(SimpanBuktiBelanjaAction::DISK);
        $path = (string) $nota->bukti_path;

        $balasan = new Response;
        $balasan->headers->set('X-Content-Type-Options', 'nosniff');
        $balasan->setPrivate();
        $balasan->headers->addCacheControlDirective('max-age', '0');
        $balasan->headers->addCacheControlDirective('must-revalidate');

        $waktu = (int) $disk->lastModified($path);
        $balasan->setLastModified(now()->setTimestamp($waktu)->toDateTimeImmutable());
        $balasan->setEtag(md5($path.'|'.$waktu.'|'.$disk->size($path)));

        /*
         * Diperiksa SEBELUM berkasnya dibaca: itulah gunanya. Isi 4 MB tidak pernah masuk
         * memori PHP untuk permintaan yang cuma butuh dijawab "tidak berubah".
         */
        if ($balasan->isNotModified($request)) {
            return $balasan;
        }

        $isi = (string) $disk->get($path);
        $mime = $this->mimeDariIsi($isi);

        $balasan->setContent($isi);
        $balasan->headers->set('Content-Type', $mime);
        $balasan->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            in_array($mime, self::MIME_INLINE, true)
                ? HeaderUtils::DISPOSITION_INLINE
                : HeaderUtils::DISPOSITION_ATTACHMENT,
            $this->namaBerkas($nota, $path),
        ));

        return $balasan;
    }

    /**
     * Mime dari ISI berkas, bukan dari ekstensinya.
     *
     * Ekstensi ditentukan saat berkasnya ditulis dan bisa saja berbeda dari isinya (berkas
     * hasil salinan lama, berkas yang dikirim ulang oleh peramban lain). Yang menentukan apa
     * yang dirender peramban adalah Content-Type yang kita kirim, jadi ia harus datang dari
     * hal yang sama dengan yang akan dibaca peramban: byte-nya sendiri. Digabung dengan
     * `nosniff`, peramban tidak akan menebak-nebak sendiri.
     */
    private function mimeDariIsi(string $isi): string
    {
        if (! function_exists('finfo_open')) {
            return 'application/octet-stream';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mime = (string) finfo_buffer($finfo, $isi);
        finfo_close($finfo);

        return $mime !== '' ? $mime : 'application/octet-stream';
    }

    /**
     * Nama berkas yang terbaca orang saat diunduh: "struk-nb-20260807-001.jpg".
     *
     * Nama sungguhannya UUID, dan UUID di folder Unduhan tidak bisa dicari lagi oleh
     * pemiliknya. Yang membaca nama ini sudah lolos semua penjaga, jadi nomor notanya bukan
     * kebocoran.
     */
    private function namaBerkas(PurchaseOrder $nota, string $path): string
    {
        $ekstensi = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return Str::slug('struk-'.$nota->nomor_po).($ekstensi !== '' ? '.'.$ekstensi : '');
    }
}
