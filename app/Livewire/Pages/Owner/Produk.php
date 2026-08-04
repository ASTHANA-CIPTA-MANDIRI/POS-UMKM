<?php

namespace App\Livewire\Pages\Owner;

use App\Actions\Stock\SiapkanBarisStokAction;
use App\Enums\Satuan;
use App\Livewire\Concerns\MengirimToast;
use App\Livewire\Concerns\TerikatTenant;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Stock;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
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

    /** semua|menipis — saringan stok, memakai aturan yang sama dengan kartu dasbor. */
    #[Url(as: 'stok')]
    public string $stok = 'semua';

    /**
     * Outlet yang stoknya sedang dilihat/disetel.
     *
     * Stok dan ambang minimum dicatat PER OUTLET, jadi layar ini selalu bekerja atas
     * satu outlet. Untuk peran yang terkunci ke satu outlet (kasir, manager outlet)
     * nilai ini tidak dipercaya — outletnya diambil dari sesi login lewat
     * scopedOutletId(). Untuk owner bercabang banyak, ini pemilihnya.
     */
    public ?string $outletStok = null;

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

    /**
     * Satuan pencatatan stok. Kosong berarti stok dicatat dalam satuan jual apa adanya.
     *
     * String kosong, bukan null, karena <select> HTML mengirim '' untuk pilihan kosong.
     */
    public string $satuanDasar = '';

    /**
     * Berapa satuan dasar di dalam satu satuan jual (1 dus = 12 pcs).
     *
     * Angka paling berbahaya di formulir ini. Ia tidak pernah salah secara terlihat:
     * kalau satuan jualnya pcs tapi isinya 12, setiap penjualan 1 pcs memotong 12 pcs
     * dari kartu stok — tanpa galat, tanpa uang yang salah, hanya stok yang meleleh
     * dan opname yang "memperbaikinya" berulang kali tanpa ada yang tahu sebabnya.
     */
    public ?float $isiPerSatuan = null;

    /** Ambang "menipis" di outlet terpilih; disimpan di baris stocks, bukan di produk. */
    public ?float $stokMinimum = null;

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

    public function mount(): void
    {
        $this->outletStok = $this->outletBawaan();
    }

    public function updated(string $properti): void
    {
        // Mengubah saringan harus mengembalikan ke halaman pertama; kalau tidak,
        // owner melihat daftar kosong hanya karena masih berada di halaman 3.
        if (in_array($properti, ['cari', 'kategoriId', 'status', 'stok', 'outletStok'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Berganti outlet harus memuat ulang ambangnya.
     *
     * Kalau tidak, ambang milik outlet A masih tertinggal di formulir dan ikut tersimpan
     * ke outlet B begitu tombol simpan ditekan — angka yang tidak pernah diketik siapa
     * pun untuk outlet itu.
     */
    public function updatedOutletStok(): void
    {
        if ($this->panel && $this->produkId !== null) {
            $this->stokMinimum = $this->ambangTersimpan(Product::findOrFail($this->produkId));
        }
    }

    public function tambah(): void
    {
        $this->reset(['produkId', 'nama', 'kategoriForm', 'harga', 'hargaBeli', 'gambar', 'gambarLama', 'hapusGambar', 'sku', 'barcode', 'satuanDasar', 'isiPerSatuan', 'stokMinimum']);
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
        $this->satuanDasar = $produk->satuan_dasar?->value ?? '';
        $this->isiPerSatuan = $produk->isi_per_satuan !== null ? (float) $produk->isi_per_satuan : null;
        $this->stokMinimum = $this->ambangTersimpan($produk);
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
            /*
             * Konversi satuan — lihat aturanIsiPerSatuan() untuk aturannya dan alasannya.
             * Satuan dasar boleh kosong (stok dicatat dalam satuan jual apa adanya), tapi
             * kombinasi yang setengah terisi TIDAK boleh lolos.
             */
            'satuanDasar' => $this->satuanDasarBersih() === null ? ['nullable'] : [Rule::enum(Satuan::class)],
            'isiPerSatuan' => $this->aturanIsiPerSatuan(),
            // Ambang stok, per outlet. Boleh 0 (= tidak dipantau), tidak boleh negatif.
            'stokMinimum' => $this->aturanStokMinimum(),
            'outletStok' => $this->aturanOutletStok(),
        ], attributes: [
            'nama' => 'nama produk',
            'kategoriForm' => 'kategori',
            'harga' => 'harga jual',
            'hargaBeli' => 'harga beli',
            'gambar' => 'gambar produk',
            'barcode' => 'barcode',
            'satuanDasar' => 'satuan dasar',
            'isiPerSatuan' => 'isi per satuan',
            'stokMinimum' => 'stok minimum',
            'outletStok' => 'outlet',
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

        $satuanDasar = $this->satuanDasarBersih();

        $muatan = [
            'nama_produk' => $data['nama'],
            'sku' => $data['sku'] !== '' ? $data['sku'] : null,
            'barcode' => $data['barcode'] !== '' ? $data['barcode'] : null,
            'gambar_path' => $pathGambar,
            'kategori_id' => $data['kategoriForm'] ?: null,
            'harga_default' => $data['harga'],
            'harga_beli' => $data['hargaBeli'],
            'satuan' => $data['satuan'],
            'satuan_dasar' => $satuanDasar,
            // Tanpa satuan dasar, faktor konversinya tidak punya arti — disimpan null
            // supaya Product::keSatuanDasar() memakai qty apa adanya.
            'isi_per_satuan' => $satuanDasar === null ? null : $this->isiPerSatuanBersih(),
            'lacak_stok' => $this->lacakStok,
            'is_active' => $this->aktif,
        ];

        if ($this->produkId !== null) {
            $produk = Product::findOrFail($this->produkId);
            $produk->update($muatan);
            $this->simpanAmbangStok($produk);
            $this->toast('Produk "'.$data['nama'].'" diperbarui.');
        } else {
            $produk = Product::create($muatan);
            $this->simpanAmbangStok($produk);
            $this->toast('Produk "'.$data['nama'].'" ditambahkan.');
        }

        $this->panel = false;
    }

    /* ── Konversi satuan ─────────────────────────────────────────────────── */

    /** '' dari <select> disamakan dengan "tidak diisi". */
    private function satuanDasarBersih(): ?string
    {
        $nilai = trim($this->satuanDasar);

        return $nilai === '' ? null : $nilai;
    }

    private function isiPerSatuanBersih(): ?float
    {
        return $this->terisi($this->isiPerSatuan) ? (float) $this->isiPerSatuan : null;
    }

    /** Nilai 0 dianggap terisi; hanya null/'' yang berarti dibiarkan kosong. */
    private function terisi(mixed $nilai): bool
    {
        return $nilai !== null && $nilai !== '';
    }

    /**
     * Aturan isi per satuan, tergantung satuan dasarnya.
     *
     * Tiga keadaan, dan ketiganya pernah ada di data sungguhan:
     *
     * 1. Tanpa satuan dasar → angka ini HARUS kosong. Kalau tetap tersimpan, tidak ada
     *    satuan tujuan yang bisa dituju, tapi Product::keSatuanDasar() tetap mengalikan:
     *    jual 1 pcs memotong 12 pcs. Tidak ada galat, tidak ada uang yang salah — hanya
     *    stok yang meleleh, dan opname yang menambalnya tiap minggu tanpa ada yang tahu
     *    kenapa.
     * 2. Satuan dasar SAMA dengan satuan jual → faktornya 1 (atau dikosongkan, artinya
     *    sama). Angka 12 di sini adalah kekeliruan yang sama seperti nomor 1.
     * 3. Satuan dasar BEDA → faktornya wajib. Membiarkannya kosong berarti 1 dus
     *    dianggap 1 pcs, dan kartu stok berhenti bisa dipercaya dari arah sebaliknya.
     *
     * @return array<int, mixed>
     */
    private function aturanIsiPerSatuan(): array
    {
        $dasar = $this->satuanDasarBersih();

        if ($dasar === null) {
            return ['nullable', function (string $atribut, mixed $nilai, Closure $gagal): void {
                if ($this->terisi($nilai)) {
                    $gagal('Isi per satuan hanya berlaku kalau satuan dasarnya dipilih.');
                }
            }];
        }

        if ($dasar === $this->satuan) {
            return ['nullable', 'numeric', function (string $atribut, mixed $nilai, Closure $gagal): void {
                if ($this->terisi($nilai) && (float) $nilai !== 1.0) {
                    $gagal('Satuan jual sama dengan satuan dasar, jadi isi per satuan harus 1 atau dibiarkan kosong.');
                }
            }];
        }

        // min 0,001 supaya konversi tidak pernah nol (pembagian di daftar belanja) dan
        // tidak pernah negatif; max 100.000 menahan salah ketik yang kebablasan.
        return ['required', 'numeric', 'min:0.001', 'max:100000'];
    }

    /* ── Ambang stok per outlet ──────────────────────────────────────────── */

    /** @return array<int, mixed> */
    private function aturanStokMinimum(): array
    {
        return ['nullable', 'numeric', 'min:0', 'max:99999999', function (string $atribut, mixed $nilai, Closure $gagal): void {
            if ($this->terisi($nilai) && $this->outletStokTerpakai() === null) {
                $gagal('Stok minimum dicatat per outlet, jadi outletnya harus dipilih dulu.');
            }
        }];
    }

    /**
     * Outlet pilihan dari klien tidak dipercaya.
     *
     * Tanpa pemeriksaan ini, muatan Livewire yang disusun sendiri bisa menitipkan
     * outlet_id milik tenant lain, dan baris stocks kita akan menunjuk outlet orang.
     *
     * Milik tenant yang benar belum cukup: outlet yang bukan tanggung jawab penggunanya
     * (Regional Manager yang hanya ditugaskan ke sebagian cabang) juga ditolak, dengan
     * pesan yang TERLIHAT. outletStokTerpakai() sudah mengabaikan nilainya sehingga tidak
     * ada yang tertulis ke outlet itu — tapi tanpa galat di sini, ambang yang sudah
     * diketik cuma hilang tanpa kabar dan orangnya percaya angkanya tersimpan.
     *
     * @return array<int, mixed>
     */
    private function aturanOutletStok(): array
    {
        return [
            'nullable',
            'uuid',
            Rule::exists('outlets', 'id')
                ->where('tenant_id', auth()->user()->tenant_id)
                ->whereNull('deleted_at'),
            function (string $atribut, mixed $nilai, Closure $gagal): void {
                $user = auth()->user();

                // Peran yang terkunci ke satu outlet: nilai dari klien memang tidak pernah
                // dipakai (outletStokTerpakai() memakai outlet sesi), jadi menolaknya hanya
                // memunculkan galat untuk kolom yang tidak ada di layarnya.
                if ($user->scopedOutletId() !== null) {
                    return;
                }

                if ($this->terisi($nilai) && ! $user->canAccessOutlet((string) $nilai)) {
                    $gagal('Outlet itu bukan tanggung jawab Anda.');
                }
            },
        ];
    }

    /**
     * Outlet yang benar-benar dipakai untuk stok — dan gerbang aksesnya.
     *
     * Peran yang terkunci ke satu outlet selalu memakai outletnya sendiri; pilihan dari
     * klien diabaikan sama sekali untuk peran itu.
     *
     * Gerbangnya dijalankan SETIAP kali outlet ini dipakai, bukan sekali di mount() —
     * alasannya sama dengan Stok::outletTerpakai(). `$outletStok` adalah properti publik,
     * jadi nilainya bisa diganti kapan saja lewat muatan pembaruan Livewire; pemeriksaan
     * yang hanya berjalan di mount cukup dilewati dengan menyetelnya belakangan. Tanpa
     * gerbang ini, Regional Manager yang hanya ditugaskan ke Outlet A bisa memaksa kolom
     * Stok dan hitungan "Menipis" di layar ini memakai Outlet B — outlet lain di tenant
     * yang sama, tapi bukan tanggung jawabnya. Aturan tenant (aturanOutletStok()) tidak
     * menangkapnya: outletnya memang milik tenant yang benar.
     *
     * Bedanya dengan Stok::outletTerpakai(): di sana outletnya datang dari URL dan SELURUH
     * halaman adalah tentang satu outlet, jadi tautan yang menyebut outlet orang lain
     * ditolak keras dengan 403. Di sini outletnya cuma penentu satu kolom pada daftar
     * produk — isi halaman lainnya tetap sah — jadi nilai yang bukan haknya DIABAIKAN
     * (dianggap "outlet belum dipilih"), persis seperti nilai dari klien untuk peran yang
     * terkunci. Yang penting: nilainya tidak dipakai untuk mengambil maupun menulis data
     * outlet itu. Nilainya sendiri tetap dibiarkan di properti supaya percobaan menyimpan
     * ambang ke outlet itu tetap ditolak dengan pesan yang terlihat — lihat
     * aturanOutletStok(), bukan gagal diam-diam.
     */
    public function outletStokTerpakai(): ?string
    {
        $user = auth()->user();
        $terkunci = $user->scopedOutletId();

        if ($terkunci !== null) {
            return $terkunci;
        }

        if (! $this->terisi($this->outletStok)) {
            return null;
        }

        return $user->canAccessOutlet($this->outletStok) ? $this->outletStok : null;
    }

    /**
     * Outlet bawaan saat halaman dibuka: outlet sesi kalau perannya terkunci, kalau
     * tidak outlet pertama milik tenant.
     *
     * Dipilih di muka, bukan dibiarkan kosong, supaya kolom stok di daftar produk tidak
     * pernah tampil kosong tanpa penjelasan. Untuk tenant bercabang banyak, pemilihnya
     * WAJIB terlihat di layar — kalau tidak, ambang bisa tersimpan ke outlet yang tidak
     * disadari orang yang mengetiknya.
     */
    private function outletBawaan(): ?string
    {
        $terkunci = auth()->user()->scopedOutletId();

        if ($terkunci !== null) {
            return $terkunci;
        }

        return Outlet::query()->orderBy('outlet_name')->value('id');
    }

    /** Ambang yang sedang tersimpan untuk outlet terpilih; null kalau belum disetel. */
    private function ambangTersimpan(Product $produk): ?float
    {
        $outletId = $this->outletStokTerpakai();

        if ($outletId === null) {
            return null;
        }

        $ambang = (float) ($produk->stocks()->where('outlet_id', $outletId)->value('stok_minimum') ?? 0);

        return $ambang > 0 ? $ambang : null;
    }

    /**
     * Menyimpan ambang minimum ke baris stok outlet terpilih.
     *
     * Menyetel ambang TIDAK membuat mutasi stok. Jumlahnya tidak berubah satu pun, jadi
     * mencatatnya di stock_movements berarti mengarang pergerakan barang yang tidak
     * pernah terjadi — dan kartu stok adalah satu-satunya bukti yang tersisa kalau nanti
     * ada selisih yang harus dijelaskan.
     *
     * Barisnya dibuat kalau outlet ini belum pernah mencatat produknya, dengan jumlah 0,
     * lewat aksi yang sama yang dipakai penjualan (SiapkanBarisStokAction) — supaya tidak
     * ada dua cara berbeda membuat baris stok.
     */
    private function simpanAmbangStok(Product $produk): void
    {
        $outletId = $this->outletStokTerpakai();

        if ($outletId === null) {
            return;
        }

        $ambang = $this->terisi($this->stokMinimum) ? (float) $this->stokMinimum : 0.0;
        $stok = $produk->stocks()->where('outlet_id', $outletId)->first();

        // Ambang kosong pada produk yang belum punya baris stok tidak perlu membuat baris
        // apa pun: "tidak dipantau" memang keadaan bawaannya.
        if ($stok === null && $ambang <= 0) {
            return;
        }

        $stok ??= app(SiapkanBarisStokAction::class)->execute(
            $produk->tenant_id,
            $outletId,
            productId: $produk->getKey(),
        );

        if ((float) $stok->stok_minimum === $ambang) {
            return;
        }

        $stok->stok_minimum = $ambang;
        $stok->save();
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

    /**
     * Kueri daftar produk beserta stok di outlet terpilih.
     *
     * LEFT join, bukan join biasa: produk yang belum pernah punya baris stok di outlet
     * ini harus tetap muncul di daftar. Kalau tidak, barang yang belum pernah diopname
     * hilang begitu saja dari layar kelola produk — dan barang yang hilang dari layar
     * adalah barang yang tidak akan pernah diperbaiki datanya.
     *
     * @return Builder<Product>
     */
    private function kueriProduk(?string $outletId): Builder
    {
        $kueri = Product::query()
            ->select('products.*')
            ->with('kategori:id,nama');

        if ($outletId !== null) {
            $kueri->leftJoin('stocks', fn (JoinClause $join) => $join
                ->on('stocks.product_id', '=', 'products.id')
                ->where('stocks.outlet_id', '=', $outletId))
                ->addSelect(['stocks.jumlah_saat_ini', 'stocks.stok_minimum']);
        }

        return $kueri
            ->when($this->cari !== '', fn ($q) => $q->where(function ($w) {
                $w->where('products.nama_produk', 'like', '%'.$this->cari.'%')
                    ->orWhere('products.sku', 'like', '%'.$this->cari.'%')
                    ->orWhere('products.barcode', 'like', '%'.$this->cari.'%');
            }))
            ->when($this->kategoriId !== '', fn ($q) => $q->where('products.kategori_id', $this->kategoriId))
            ->when($this->status === 'aktif', fn ($q) => $q->where('products.is_active', true))
            ->when($this->status === 'nonaktif', fn ($q) => $q->where('products.is_active', false))
            ->when($this->stok === 'menipis', fn ($q) => $this->saringMenipis($q, $outletId));
    }

    /**
     * Saringan "menipis" — aturannya diambil dari Stock::saringMenipis(), definisi yang
     * sama yang dipakai kartu dasbor. Angka di dasbor dan isi daftar ini tidak boleh
     * berbeda; kalau berbeda, salah satunya berbohong dan owner tidak tahu yang mana.
     *
     * @param  Builder<Product>  $kueri
     */
    private function saringMenipis(Builder $kueri, ?string $outletId): void
    {
        if ($outletId === null) {
            // Tanpa outlet, "menipis" tidak punya jawaban. Daftar kosong lebih jujur
            // daripada menampilkan semua produk seolah semuanya menipis.
            $kueri->whereRaw('1 = 0');

            return;
        }

        Stock::saringMenipis($kueri);

        /*
         * Dua jenis produk dikecualikan:
         * - berbasis resep: yang berkurang saat terjual adalah BAHAN BAKUNYA, jadi baris
         *   stok produk jadinya tidak pernah bergerak dan akan tampak "menipis" abadi;
         * - lacak_stok mati: jumlahnya memang tidak pernah dicatat, jadi memasukkannya ke
         *   daftar belanja hanya membuat owner berhenti mempercayai daftar itu.
         */
        $kueri->where('products.lacak_stok', true)->whereDoesntHave('recipeItems');
    }

    /** @return array<int, array{id: string, nama: string}> */
    private function outletTersedia(): array
    {
        return Outlet::query()
            ->orderBy('outlet_name')
            ->get(['id', 'outlet_name'])
            ->map(fn (Outlet $outlet) => ['id' => $outlet->getKey(), 'nama' => $outlet->outlet_name])
            ->all();
    }

    public function render()
    {
        $outletId = $this->outletStokTerpakai();

        return view('livewire.pages.owner.produk', [
            'daftar' => $this->kueriProduk($outletId)
                ->orderBy('products.nama_produk')
                ->paginate(config('nampan.per_halaman')),
            'kategori' => Category::orderBy('urutan')->orderBy('nama')->get(['id', 'nama']),
            'satuanTersedia' => Satuan::cases(),
            'jumlahAktif' => Product::where('is_active', true)->count(),
            'jumlahNonaktif' => Product::where('is_active', false)->count(),
            'jumlahMenipis' => $outletId === null ? 0 : Product::query()
                ->tap(fn (Builder $q) => $this->saringMenipis(
                    $q->leftJoin('stocks', fn (JoinClause $join) => $join
                        ->on('stocks.product_id', '=', 'products.id')
                        ->where('stocks.outlet_id', '=', $outletId)),
                    $outletId,
                ))
                ->count(),
            // Pemilih outlet: hanya berarti untuk peran yang tidak terkunci ke satu outlet.
            'outletTersedia' => auth()->user()->scopedOutletId() === null ? $this->outletTersedia() : [],
            'outletDipakai' => $outletId,
        ]);
    }
}
