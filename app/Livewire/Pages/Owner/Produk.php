<?php

namespace App\Livewire\Pages\Owner;

use App\Enums\Satuan;
use App\Livewire\Concerns\MengirimToast;
use App\Livewire\Concerns\TerikatTenant;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Kelola produk: daftar, tambah, ubah, aktif/nonaktif.
 *
 * Halaman ini yang menentukan isi layar kasir — harga yang salah di sini langsung
 * menjadi uang yang salah di kasir. Karena itu harga divalidasi wajib dan tidak
 * boleh negatif, dan produk TIDAK pernah dihapus permanen dari layar ini:
 * menonaktifkan menyembunyikannya dari kasir tanpa memutus transaksi lama yang
 * masih menunjuk produk itu.
 */
#[Layout('layouts.aplikasi')]
class Produk extends Component
{
    use MengirimToast, TerikatTenant, WithFileUploads, WithPagination;

    #[Url(as: 'cari')]
    public string $cari = '';

    #[Url(as: 'kategori')]
    public string $kategoriId = '';

    #[Url(as: 'status')]
    public string $status = 'semua';

    /* ── Formulir ────────────────────────────────────────────────────────── */

    public bool $panel = false;

    public ?string $produkId = null;

    public string $nama = '';

    public ?string $kategoriForm = null;

    public string $sku = '';

    public string $barcode = '';

    public ?float $harga = null;

    public ?float $hargaBeli = null;

    public string $satuan = 'pcs';

    public bool $lacakStok = true;

    public bool $aktif = true;

    /**
     * Berkas gambar yang baru dipilih, belum tersimpan.
     *
     * Dipisah dari $gambarLama supaya membuka formulir tidak pernah kehilangan gambar
     * yang sudah ada hanya karena kolom unggahnya dibiarkan kosong.
     */
    public $gambar = null;

    public ?string $gambarLama = null;

    public bool $hapusGambar = false;

    public function updated(string $properti): void
    {
        // Mengubah saringan harus mengembalikan ke halaman pertama; kalau tidak,
        // owner melihat daftar kosong hanya karena masih berada di halaman 3.
        if (in_array($properti, ['cari', 'kategoriId', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function tambah(): void
    {
        $this->reset(['produkId', 'nama', 'kategoriForm', 'harga', 'hargaBeli', 'gambar', 'gambarLama', 'hapusGambar', 'sku', 'barcode']);
        $this->satuan = 'pcs';
        $this->lacakStok = true;
        $this->aktif = true;
        $this->panel = true;
        $this->resetValidation();
    }

    public function ubah(string $id): void
    {
        $produk = Product::findOrFail($id);

        $this->produkId = $produk->getKey();
        $this->nama = $produk->nama_produk;
        $this->kategoriForm = $produk->kategori_id;
        $this->sku = (string) $produk->sku;
        $this->barcode = (string) $produk->barcode;
        $this->harga = (float) $produk->harga_default;
        $this->hargaBeli = $produk->harga_beli !== null ? (float) $produk->harga_beli : null;
        $this->satuan = $produk->satuan?->value ?? 'pcs';
        $this->lacakStok = (bool) $produk->lacak_stok;
        $this->aktif = (bool) $produk->is_active;
        $this->gambar = null;
        $this->gambarLama = $produk->gambar_path;
        $this->hapusGambar = false;
        $this->panel = true;
        $this->resetValidation();
    }

    /** Menandai gambar lama untuk dibuang; berkasnya baru dihapus saat disimpan. */
    public function tandaiHapusGambar(): void
    {
        $this->hapusGambar = true;
        $this->gambar = null;
    }

    public function tutupPanel(): void
    {
        $this->panel = false;
        $this->resetValidation();
    }

    public function simpan(): void
    {
        $data = $this->validate([
            'nama' => ['required', 'string', 'max:255'],
            'kategoriForm' => ['nullable', 'uuid', Rule::exists('categories', 'id')],
            // Harga wajib: produk tanpa harga akan tampil Rp 0 di kasir dan terjual
            // gratis tanpa ada yang menyadarinya sampai tutup kasir.
            'harga' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'hargaBeli' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'satuan' => ['required', Rule::enum(Satuan::class)],
            /*
             * SKU & barcode unik PER TENANT, bukan global: dua merchant berbeda wajar
             * menjual barang yang sama dengan barcode pabrik yang sama. Baris produk
             * yang sedang diubah dikecualikan supaya menyimpan tanpa mengubah
             * barcodenya tidak ditolak oleh dirinya sendiri.
             */
            'sku' => ['nullable', 'string', 'max:64', $this->unikPerTenant('sku')],
            'barcode' => ['nullable', 'string', 'max:64', $this->unikPerTenant('barcode')],
            /*
             * Dibatasi 2 MB dan hanya format gambar umum. Aplikasi ini TIDAK mengecilkan
             * gambar, jadi berkas yang diunggah adalah berkas yang nanti diunduh tablet
             * kasir — foto 8 MB dari kamera ponsel akan membuat grid produk lambat
             * dimuat justru di jaringan warung yang paling lemah.
             */
            'gambar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], attributes: [
            'nama' => 'nama produk',
            'kategoriForm' => 'kategori',
            'harga' => 'harga jual',
            'hargaBeli' => 'harga beli',
            'gambar' => 'gambar produk',
            'barcode' => 'barcode',
        ]);

        $pathGambar = $this->gambarLama;

        if ($this->gambar !== null) {
            $pathGambar = $this->gambar->store('produk', 'public');

            // Berkas lama dibuang setelah yang baru tersimpan, bukan sebelumnya:
            // kalau penyimpanan gagal, produk tidak kehilangan gambarnya.
            $this->buangBerkas($this->gambarLama);
        } elseif ($this->hapusGambar) {
            $this->buangBerkas($this->gambarLama);
            $pathGambar = null;
        }

        $muatan = [
            'nama_produk' => $data['nama'],
            'sku' => $data['sku'] !== '' ? $data['sku'] : null,
            'barcode' => $data['barcode'] !== '' ? $data['barcode'] : null,
            'gambar_path' => $pathGambar,
            'kategori_id' => $data['kategoriForm'] ?: null,
            'harga_default' => $data['harga'],
            'harga_beli' => $data['hargaBeli'],
            'satuan' => $data['satuan'],
            'lacak_stok' => $this->lacakStok,
            'is_active' => $this->aktif,
        ];

        if ($this->produkId !== null) {
            Product::findOrFail($this->produkId)->update($muatan);
            $this->toast('Produk "'.$data['nama'].'" diperbarui.');
        } else {
            Product::create($muatan);
            $this->toast('Produk "'.$data['nama'].'" ditambahkan.');
        }

        $this->panel = false;
    }

    /** Produk yang menunggu konfirmasi hapus; null berarti tidak ada. */
    public ?string $produkMauDihapus = null;

    public function tandaiHapus(string $id): void
    {
        $this->produkMauDihapus = $id;
    }

    public function batalHapus(): void
    {
        $this->produkMauDihapus = null;
    }

    /**
     * Menghapus produk beserta berkas gambarnya.
     *
     * Yang dipakai adalah SOFT DELETE, bukan hapus permanen dari database. Baris
     * transaction_items menunjuk product_id, dan menghapus barisnya secara permanen
     * akan memutus laporan penjualan lama — omzet bulan lalu bisa berubah hanya karena
     * hari ini ada menu yang dihentikan.
     *
     * Berkas gambarnya IKUT dihapus dari disk. Konsekuensinya: produk yang nanti
     * dipulihkan lewat database akan kembali tanpa gambar. Itu pilihan yang sadar —
     * menyimpan berkas yang tidak pernah ditampilkan lagi hanya menumpuk sampah di
     * disk, dan belum ada layar pemulihan yang membutuhkannya.
     */
    public function hapus(string $id): void
    {
        $produk = Product::findOrFail($id);
        $nama = $produk->nama_produk;

        $this->buangBerkas($produk->gambar_path);
        $produk->update(['gambar_path' => null]);
        $produk->delete();

        $this->produkMauDihapus = null;

        $this->toast('Produk "'.$nama.'" dihapus. Riwayat penjualannya tetap utuh.');
    }

    /**
     * Aturan unik per tenant, mengecualikan baris yang sedang diubah.
     *
     * Kolomnya nullable dan unique(['tenant_id', kolom]) di database, jadi banyak
     * produk boleh sama-sama kosong — yang dilarang hanya nilai KEMBAR yang terisi.
     */
    private function unikPerTenant(string $kolom): Unique
    {
        return Rule::unique('products', $kolom)
            ->where('tenant_id', auth()->user()->tenant_id)
            ->whereNull('deleted_at')
            ->ignore($this->produkId);
    }

    /** Jenis usaha menentukan apakah kolom barcode ditampilkan. */
    public function pakaiBarcode(): bool
    {
        return auth()->user()->tenant?->business_type?->pakaiBarcode() ?? false;
    }

    /**
     * Apakah berkas gambarnya benar-benar ada di disk.
     *
     * Path di database bisa menggantung: berkasnya terhapus di luar aplikasi, disk
     * dipindah, atau salinan database dibawa ke mesin lain tanpa berkasnya. Tanpa
     * pemeriksaan ini, layar menampilkan ikon gambar rusak — dan ikon rusak tidak
     * memberi tahu apa pun selain "ada yang salah".
     *
     * Biayanya satu pemeriksaan berkas per baris; untuk 15 baris per halaman itu
     * jauh lebih murah daripada menyesatkan orang yang melihatnya.
     */
    public function punyaGambar(?string $path): bool
    {
        return $path !== null && $path !== '' && Storage::disk('public')->exists($path);
    }

    /** Menghapus berkas gambar dari disk publik bila memang ada. */
    private function buangBerkas(?string $path): void
    {
        if ($path !== null && $path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Menonaktifkan, bukan menghapus.
     *
     * Produk yang dihapus akan memutus transaksi lama yang menunjuknya, dan laporan
     * penjualan kehilangan namanya. Nonaktif menyembunyikannya dari kasir sambil
     * menjaga seluruh riwayat tetap terbaca.
     */
    public function ubahAktif(string $id): void
    {
        $produk = Product::findOrFail($id);
        $produk->update(['is_active' => ! $produk->is_active]);

        $this->toast($produk->is_active
            ? 'Produk "'.$produk->nama_produk.'" ditampilkan lagi di kasir.'
            : 'Produk "'.$produk->nama_produk.'" disembunyikan dari kasir.');
    }

    public function render()
    {
        return view('livewire.pages.owner.produk', [
            'daftar' => Product::query()
                ->with('kategori:id,nama')
                ->when($this->cari !== '', fn ($q) => $q->where(function ($w) {
                    $w->where('nama_produk', 'like', '%'.$this->cari.'%')
                        ->orWhere('sku', 'like', '%'.$this->cari.'%')
                        ->orWhere('barcode', 'like', '%'.$this->cari.'%');
                }))
                ->when($this->kategoriId !== '', fn ($q) => $q->where('kategori_id', $this->kategoriId))
                ->when($this->status === 'aktif', fn ($q) => $q->where('is_active', true))
                ->when($this->status === 'nonaktif', fn ($q) => $q->where('is_active', false))
                ->orderBy('nama_produk')
                ->paginate(15),
            'kategori' => Category::orderBy('urutan')->orderBy('nama')->get(['id', 'nama']),
            'satuanTersedia' => Satuan::cases(),
            'jumlahAktif' => Product::where('is_active', true)->count(),
            'jumlahNonaktif' => Product::where('is_active', false)->count(),
        ]);
    }
}
