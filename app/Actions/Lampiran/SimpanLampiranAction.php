<?php

namespace App\Actions\Lampiran;

use App\Models\Lampiran\Lampiran;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Menyimpan beberapa lampiran sekaligus ke satu dokumen.
 *
 * ATURAN PALING PENTING, dan ia mengatur seluruh bentuk kelas ini: **kegagalan lampiran
 * tidak pernah menggagalkan dokumennya.** Nota belanja adalah catatan uang keluar;
 * fotonya penguat. Kalau satu berkas 9 MB dari kamera ponsel membuang nota 12 baris yang
 * sudah diketik di depan grosir, orang yang kehilangan isian sekali akan berhenti
 * mencatat sama sekali — dan yang hilang jauh lebih mahal daripada fotonya.
 *
 * Karena itu execute() TIDAK PERNAH melempar. Ia mengembalikan laporan: berapa yang
 * masuk, dan yang tidak masuk beserta ALASANNYA per berkas — supaya layarnya bisa
 * menyebut nama berkas yang gagal, bukan "sebagian gagal".
 *
 * Tiap berkas ditulis SENDIRI-SENDIRI, tanpa transaksi yang membungkus penulisan disk.
 * Yang berhasil tetap terpasang; yang gagal disebut namanya. Transaksi di sini justru
 * merugikan: satu foto rusak membatalkan empat foto yang sudah baik.
 */
class SimpanLampiranAction
{
    /**
     * Batas jumlah lampiran per dokumen.
     *
     * Pemilik proyek memutuskan 10 (2026-08-07): ada nota grosir yang lembarnya banyak dan
     * tiap lembar mau difoto terpisah supaya terbaca.
     */
    public const MAKS = 10;

    /** Folder untuk lampiran BARU. Baris lama tetap memakai path-nya sendiri. */
    public const FOLDER = 'lampiran';

    /** Jenis yang bisa DIBUKA pemiliknya sendiri — itu syaratnya, bukan sekadar "gambar". */
    public const MIME_DITERIMA = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /**
     * @param  array<int, mixed>  $berkas
     * @return array{masuk: int, ditolak: array<int, array{nama: string, sebab: string}>}
     */
    public function execute(Model $induk, array $berkas, ?string $penggunaId = null): array
    {
        $ditolak = [];
        $masuk = 0;

        $terpakai = Lampiran::query()
            ->where('lampirable_type', $induk->getMorphClass())
            ->where('lampirable_id', $induk->getKey())
            ->count();

        $urutan = (int) Lampiran::query()
            ->where('lampirable_type', $induk->getMorphClass())
            ->where('lampirable_id', $induk->getKey())
            ->max('urutan');

        foreach ($berkas as $satu) {
            if (! $satu instanceof UploadedFile) {
                continue;
            }

            $nama = $satu->getClientOriginalName();

            if ($terpakai + $masuk >= self::MAKS) {
                $ditolak[] = ['nama' => $nama, 'sebab' => 'nota ini paling banyak '.self::MAKS.' lampiran'];

                continue;
            }

            $sebab = $this->tolak($satu);

            if ($sebab !== null) {
                $ditolak[] = ['nama' => $nama, 'sebab' => $sebab];

                continue;
            }

            try {
                $mime = $this->mimeIsi($satu);
                $tujuan = self::FOLDER.'/'.$induk->getAttribute('tenant_id').'/'
                    .Str::uuid()->toString().'.'.self::MIME_DITERIMA[$mime];

                // put() dengan isi, BUKAN store(): TemporaryUploadedFile::storeAs()
                // menyusun path-nya sendiri dan mengabaikan yang kita tentukan.
                if (Storage::disk(Lampiran::DISK)->put($tujuan, $satu->get()) === false) {
                    $ditolak[] = ['nama' => $nama, 'sebab' => 'gagal disimpan, coba lagi'];

                    continue;
                }

                Lampiran::create([
                    'lampirable_type' => $induk->getMorphClass(),
                    'lampirable_id' => $induk->getKey(),
                    'path' => $tujuan,
                    // Nama asli DIPANGKAS dan hanya untuk ditampilkan; ia tidak pernah ikut
                    // menyusun path, jadi "../" di dalamnya tidak berakibat apa pun.
                    'nama_asli' => mb_substr($nama, 0, 150),
                    'mime' => $mime,
                    'ukuran' => $satu->getSize(),
                    'urutan' => ++$urutan,
                    'diunggah_oleh' => $penggunaId,
                ]);

                $masuk++;
            } catch (Throwable $e) {
                // Ditelan dengan sengaja: lihat catatan kelas. Yang penting laporannya
                // menyebut berkas MANA yang gagal.
                $ditolak[] = ['nama' => $nama, 'sebab' => 'gagal disimpan, coba lagi'];
            }
        }

        return ['masuk' => $masuk, 'ditolak' => $ditolak];
    }

    /**
     * Alasan sebuah berkas ditolak, atau null kalau diterima.
     *
     * Dua batas ukuran yang berbeda, dan asimetrinya disengaja: foto di atas 4 MB berarti
     * kamera yang salah setelan (dan unggahan yang tidak selesai di sinyal warung),
     * sedangkan PDF hasil pindai tiga halaman dari grosir memang wajar 5–6 MB. Satu batas
     * 8 MB untuk keduanya mengundang foto 8 MB; satu batas 4 MB menolak invoice yang sah.
     */
    private function tolak(UploadedFile $berkas): ?string
    {
        $mime = $this->mimeIsi($berkas);

        if (! isset(self::MIME_DITERIMA[$mime])) {
            return 'cuma JPG, PNG, WEBP, atau PDF yang bisa dibaca di sini';
        }

        $maksKb = $mime === 'application/pdf'
            ? (int) config('nampan.lampiran_pdf_maks_kb', 8192)
            : (int) config('nampan.bukti_maks_kb', 4096);

        if ($berkas->getSize() > $maksKb * 1024) {
            return ($mime === 'application/pdf' ? 'PDF' : 'Foto').' paling besar '
                .number_format($maksKb / 1024, 0, ',', '.').' MB';
        }

        return null;
    }

    /**
     * Jenis berkas dari ISI-nya — dibaca finfo, BUKAN dari `getMimeType()`.
     *
     * Ini bukan kehati-hatian berlebihan, dan bedanya sudah diukur di mesin ini:
     *
     *     berkas HTML bernama "invoice.pdf"
     *       getMimeType()       -> application/pdf     (ditebak dari EKSTENSI)
     *       getClientMimeType() -> application/pdf     (dikarang peramban)
     *       finfo atas isinya   -> text/html           <- yang benar
     *
     * Versi pertama kelas ini memakai getMimeType() sambil MENGAKU membaca isi berkas, dan
     * ujinya membuktikan sebaliknya: berkas HTML lolos sebagai PDF. Kalau itu sampai
     * tersimpan, ia disajikan dari origin kita sendiri, dengan sesi pemiliknya.
     *
     * finfo membaca byte awal berkasnya, jadi nama dan ekstensi tidak berperan sama sekali.
     */
    private function mimeIsi(UploadedFile $berkas): string
    {
        $jalur = $berkas->getRealPath();

        if ($jalur === false || ! is_readable($jalur)) {
            // Tidak bisa dibaca = tidak bisa dipercaya. Bukan jatuh ke getMimeType(), karena
            // yang jatuh ke situ persis kasus yang mau ditahan.
            return 'application/octet-stream';
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($jalur);

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    /** Membuang satu lampiran beserta berkasnya. Baris tanpa berkas tetap terhapus. */
    public function hapus(Lampiran $lampiran): void
    {
        if (Storage::disk(Lampiran::DISK)->exists($lampiran->path)) {
            Storage::disk(Lampiran::DISK)->delete($lampiran->path);
        }

        $lampiran->delete();
    }
}
