<?php

namespace App\Livewire\Pages\Owner\Produk;

use App\Actions\Produk\ImporProdukAction;
use App\Livewire\Concerns\MengirimToast;
use App\Livewire\Concerns\TerikatTenant;
use App\Support\BacaCsv;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Impor produk dari CSV — unggah, LIHAT DULU, baru simpan.
 *
 * KENAPA ADA LANGKAH PRATINJAU, padahal ia menambah satu ketukan. Mengimpor 300 baris yang
 * salah jauh lebih buruk daripada menolak berkasnya: produk yang telanjur masuk harus dihapus
 * satu per satu, sebagian sudah dipakai transaksi sehingga tidak bisa dihapus lagi, dan harga
 * yang salah sudah dipakai kasir hari itu. Satu ketukan tambahan membeli seluruh itu.
 *
 * BERKASNYA disimpan sementara, BUKAN hasil pemeriksaannya. Muatan yang dikirim balik peramban
 * bisa disunting, dan yang disunting di sini adalah harga barang — jadi `simpan()` membaca
 * berkasnya lagi dan memeriksa ulang dari nol. Pratinjau adalah kabar, bukan perintah.
 */
#[Layout('layouts.aplikasi')]
class ImporProduk extends Component
{
    use MengirimToast, TerikatTenant, WithFileUploads;

    /**
     * Batas ukuran berkas dalam kilobita.
     *
     * 2 MB jauh di atas kebutuhan (CSV 2.000 baris produk sekitar 120 KB) dan cukup rendah
     * untuk menahan orang yang tidak sengaja mengunggah .xlsx berisi gambar. Batas barisnya
     * sendiri dijaga BacaCsv::MAKS_BARIS.
     */
    public const MAKS_KB = 2048;

    public $berkas;

    /**
     * Jalur berkas yang sudah diunggah, di cakram privat.
     *
     * #[Locked]: tanpa ini, muatan Livewire bisa menukarnya ke jalur lain di cakram yang sama
     * dan menyuruh server membaca berkas milik orang lain. Diisi hanya oleh server.
     */
    #[Locked]
    public ?string $jalurSementara = null;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $pratinjau = null;

    public function updatedBerkas(): void
    {
        $this->validate([
            /*
             * `mimes:csv,txt` DAN `extensions`, bukan salah satu. Deteksi MIME untuk CSV
             * sangat tidak bisa dipercaya — berkas CSV yang sama dilaporkan text/plain,
             * text/csv, atau application/vnd.ms-excel tergantung sistem yang mengunggahnya.
             * Menolak berdasarkan MIME saja membuat berkas yang benar ditolak di komputer
             * tertentu saja, dan itu keluhan yang tidak bisa ditiru siapa pun.
             */
            'berkas' => ['required', 'file', 'extensions:csv,txt', 'max:'.self::MAKS_KB],
        ], attributes: ['berkas' => 'berkas CSV']);

        // Disimpan ke cakram privat, BUKAN public: daftar harga beli seluruh barang bocor
        // lewat satu tautan kalau ia berada di folder yang disajikan web server.
        $this->jalurSementara = $this->berkas->store('impor-produk', 'local');

        $isi = Storage::disk('local')->get($this->jalurSementara);

        $this->pratinjau = app(ImporProdukAction::class)->periksa((string) $isi);
    }

    public function batal(): void
    {
        $this->bersihkan();
        $this->reset(['berkas', 'pratinjau']);
        $this->resetValidation();
    }

    public function simpan(): void
    {
        if ($this->jalurSementara === null || ! Storage::disk('local')->exists($this->jalurSementara)) {
            $this->toast('Berkasnya sudah tidak ada. Unggah ulang, ya.', 'galat');
            $this->batal();

            return;
        }

        $isi = (string) Storage::disk('local')->get($this->jalurSementara);

        // Diperiksa ULANG dari berkasnya, bukan memakai $this->pratinjau. Alasannya di
        // docblock kelas: pratinjau adalah kabar, bukan perintah.
        $hasil = app(ImporProdukAction::class)->simpan($isi);

        $this->bersihkan();
        $this->reset(['berkas', 'pratinjau']);

        if ($hasil['baru'] === 0 && $hasil['diperbarui'] === 0) {
            $this->toast('Tidak ada baris yang bisa dimasukkan. Periksa lagi berkasnya.', 'peringatan');

            return;
        }

        $this->toast(
            $hasil['baru'].' barang baru masuk'
            .($hasil['diperbarui'] > 0 ? ', '.$hasil['diperbarui'].' diperbarui' : '')
            .($hasil['ditolak'] > 0 ? ', '.$hasil['ditolak'].' baris dilewati' : '').'.',
        );
    }

    /**
     * Berkas sementara dibuang setelah dipakai.
     *
     * Isinya daftar harga beli seluruh barang. Membiarkannya menumpuk di server berarti
     * satu folder yang isinya makin lama makin berharga bagi siapa pun yang menemukannya —
     * dan tidak ada satu pun layar yang memperlihatkan bahwa berkas itu masih ada.
     */
    private function bersihkan(): void
    {
        if ($this->jalurSementara !== null) {
            Storage::disk('local')->delete($this->jalurSementara);
            $this->jalurSementara = null;
        }
    }

    /** Templat CSV, supaya pemilik tidak menebak nama kolomnya. */
    public function unduhTemplat()
    {
        return response()->streamDownload(
            fn () => print (ImporProdukAction::templat()),
            'templat-produk-nampan.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    public function render()
    {
        return view('livewire.pages.owner.produk.impor-produk', [
            'maksBaris' => BacaCsv::MAKS_BARIS,
            'maksMb' => (int) round(self::MAKS_KB / 1024),
        ]);
    }
}
