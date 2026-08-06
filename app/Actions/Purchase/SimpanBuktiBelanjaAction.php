<?php

namespace App\Actions\Purchase;

use App\Enums\DocumentStatus;
use App\Models\PurchaseOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Memasang, mengganti, dan membuang foto bukti belanja (kwitansi/struk) sebuah nota.
 *
 * SATU jalur untuk dua layar: formulir nota baru (foto ikut saat nota disimpan) dan panel
 * rincian nota (foto dipasang belakangan). Dua jalur berarti dua tempat yang memutuskan
 * folder, batas ukuran, dan siapa yang boleh mengubah — dan yang satu akan ketinggalan saat
 * yang lain diperbaiki.
 *
 * EMPAT KEPUTUSAN YANG MENENTUKAN, semuanya lahir dari kerugian nyata:
 *
 * 1. **TIDAK PERNAH melempar karena berkasnya bermasalah.** Aksi ini mengembalikan bool.
 *    Nota belanja adalah catatan uang keluar; foto adalah penguat. Kegagalan mengunggah
 *    foto TIDAK BOLEH menggagalkan penyimpanan nota — sinyal warung mati di tengah unggahan
 *    adalah kejadian sehari-hari, dan nota yang hilang karenanya berarti pemilik mengetik
 *    ulang 12 baris (dan sebagian orang berhenti mencatat sama sekali).
 *
 * 2. **Foldernya `bukti-belanja/{tenant_id}/`, BUKAN `produk/`.** Dua alasan yang keduanya
 *    keras: aturan nomor 1 CLAUDE.md melindungi `produk/` (gambar pengguna pernah hilang
 *    permanen), dan mencampur berkas non-produk ke situ membuat skrip pembersih "gambar
 *    produk yatim" kelak menghapus bukti belanja — berkas yang tidak punya pengganti.
 *    `tenant_id` ada di path karena bukti memuat nama pemasok dan harga beli; itu bukan
 *    sekadar foto sabun.
 *
 * 3. **Nota yang DIBATALKAN terkunci, dan berkasnya tidak pernah disentuh.** Nota batal
 *    karena barangnya dikembalikan ke grosir, dan struk itu justru satu-satunya bukti
 *    pengembaliannya. Boleh dilihat, tidak boleh ditambah/diganti/dihapus (aturan keras 6:
 *    jangan menghapus data permanen).
 *
 * 4. **Berkas lama dibuang SESUDAH yang baru tersimpan** (pola Produk::buangBerkas()).
 *    Dibuang lebih dulu berarti unggahan yang gagal meninggalkan nota tanpa foto sama
 *    sekali — kehilangan yang tidak diminta siapa pun.
 */
class SimpanBuktiBelanjaAction
{
    /**
     * Folder induk, di disk publik.
     *
     * Sengaja bukan konstanta bernama 'produk' apa pun: uji folder memeriksa nilai ini,
     * dan menyimpannya di satu tempat membuat pemindahan folder kelak tidak menyisakan
     * satu pemanggil yang masih menulis ke tempat lama.
     */
    public const FOLDER = 'bukti-belanja';

    /**
     * Aturan validasi berkas — SATU definisi, dipakai layar (untuk pesan galat) dan aksi
     * ini (untuk pemeriksaan diam sebelum menulis).
     *
     * PENTING: aturan ini TIDAK BOLEH ikut ke dalam validator yang menggagalkan
     * penyimpanan nota. Ia dipakai dua kali dengan cara berbeda: di layar lewat
     * updatedBukti() supaya orang langsung tahu fotonya terlalu besar, dan di sini lewat
     * Validator yang HANYA ditanya fails() — tidak melempar apa pun.
     *
     * @return array<int, string>
     */
    public static function aturan(): array
    {
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::maksKb()];
    }

    /**
     * Pesan berbahasa Indonesia.
     *
     * Wajib ada: pesan bawaan Laravel di repo ini BERBAHASA INGGRIS (APP_LOCALE=en, tanpa
     * lang/id), jadi tanpa blok ini pemilik warung membaca "The foto bukti must not be
     * greater than 4096 kilobytes." di layarnya sendiri.
     *
     * @return array<string, string>
     */
    public static function pesan(): array
    {
        return [
            'image' => 'Bukti belanja harus berupa foto — JPG, PNG, atau WEBP.',
            'mimes' => 'Bukti belanja harus berupa foto — JPG, PNG, atau WEBP.',
            'max' => 'Fotonya kelewat besar. Paling besar '.self::labelBatas()
                .' — potret ulang dengan ukuran lebih kecil, atau simpan notanya dulu tanpa foto.',
        ];
    }

    public static function maksKb(): int
    {
        // Angkanya TIDAK diketik di sini maupun di layar: satu setelan, supaya batas yang
        // diuji sama dengan batas yang diberitahukan ke pengguna.
        return (int) config('nampan.bukti_maks_kb');
    }

    /** "4 MB" kalau bulat, "3500 KB" kalau tidak — yang dibaca orang, bukan yang dihitung mesin. */
    public static function labelBatas(): string
    {
        $kb = self::maksKb();

        return $kb > 0 && $kb % 1024 === 0
            ? intdiv($kb, 1024).' MB'
            : $kb.' KB';
    }

    /**
     * Boleh ditambah/diganti/dihapus?
     *
     * Nota dibatalkan = terkunci. Diperiksa DI SINI, bukan hanya di layar: dialog
     * konfirmasi dan tombol yang disembunyikan bukan pengaman — muatan Livewire bisa
     * dikirim tanpa pernah melewati layar mana pun.
     */
    public function bolehDiubah(PurchaseOrder $nota): bool
    {
        return $nota->status !== DocumentStatus::Dibatalkan;
    }

    /**
     * Memasang/mengganti foto bukti. true = terpasang, false = tidak (nota tidak berubah).
     *
     * `$berkas` bertipe lepas karena yang datang dari Livewire adalah TemporaryUploadedFile;
     * yang penting ia UploadedFile.
     */
    public function execute(PurchaseOrder $nota, mixed $berkas): bool
    {
        if (! $berkas instanceof UploadedFile) {
            return false;
        }

        if (! $this->bolehDiubah($nota)) {
            // Berkas LAMA tidak disentuh sama sekali, dan berkas baru tidak ditulis.
            return false;
        }

        // Pemeriksaan diam. fails(), bukan validate(): di sini kita sudah berada SESUDAH
        // nota tersimpan, dan melempar ValidationException dari titik ini berarti pemilik
        // melihat pesan galat untuk nota yang sebenarnya berhasil disimpan.
        $salah = Validator::make(['bukti' => $berkas], ['bukti' => self::aturan()])->fails();

        if ($salah) {
            return false;
        }

        $lama = (string) $nota->bukti_path;

        try {
            $tujuan = self::FOLDER.'/'.$nota->tenant_id.'/'.Str::uuid()->toString().'.'.$this->ekstensi($berkas);

            /*
             * put(), BUKAN $berkas->store().
             *
             * TemporaryUploadedFile::storeAs() mengembalikan path yang disusunnya SENDIRI
             * tanpa memeriksa apakah penulisannya berhasil — jadi disk penuh atau izin
             * folder yang salah menghasilkan path yang tampak sah di kolom database
             * sementara berkasnya tidak ada. put() mengembalikan bool, dan bool itulah yang
             * menentukan apakah kolomnya ikut diisi.
             */
            $berhasil = Storage::disk('public')->put($tujuan, $berkas->get());

            if ($berhasil !== true) {
                return false;
            }
        } catch (Throwable) {
            // Berkas sementara sudah dibersihkan, disk mati, kuota habis. Semuanya berakhir
            // sama: notanya tetap utuh, fotonya tidak terpasang, dan layarnya berterus
            // terang tentang itu.
            return false;
        }

        $nota->bukti_path = $tujuan;
        $nota->save();

        // Sesudah yang baru tersimpan DAN kolomnya menunjuk ke sana — bukan sebelumnya.
        $this->buangBerkas($lama);

        return true;
    }

    /** Membuang foto bukti. true = ada yang dibuang. */
    public function hapus(PurchaseOrder $nota): bool
    {
        if (! $this->bolehDiubah($nota)) {
            return false;
        }

        $lama = (string) $nota->bukti_path;

        if ($lama === '') {
            return false;
        }

        $nota->bukti_path = null;
        $nota->save();

        $this->buangBerkas($lama);

        return true;
    }

    /**
     * Ekstensi dari isi berkasnya, bukan dari nama yang dikirim klien.
     *
     * Nama berkas dari peramban bisa apa saja (termasuk kosong, termasuk ".jpeg.php");
     * yang dipakai adalah dugaan dari mime-nya. 'jpg' sebagai jaring terakhir supaya berkas
     * tanpa ekstensi tidak lahir sebagai path berakhir titik.
     */
    private function ekstensi(UploadedFile $berkas): string
    {
        $ekstensi = (string) ($berkas->extension() ?: $berkas->getClientOriginalExtension());

        return $ekstensi !== '' ? strtolower($ekstensi) : 'jpg';
    }

    /** Pola yang sama dengan Produk::buangBerkas() — hanya membuang yang memang ada. */
    private function buangBerkas(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
