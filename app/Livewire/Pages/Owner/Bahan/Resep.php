<?php

namespace App\Livewire\Pages\Owner\Bahan;

use App\Livewire\Concerns\MengirimToast;
use App\Livewire\Concerns\TerikatTenant;
use App\Models\Bahan\RawMaterial;
use App\Models\Bahan\RecipeItem;
use App\Models\Produk\Product;
use App\Models\Stok\Stock;
use App\Support\Uang;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Resep menu: berapa bahan mentah yang habis untuk 1 porsi.
 *
 * INI yang menyalakan hal-hal yang sudah dibayar tapi belum bisa dipakai. Begitu sebuah
 * menu punya resep:
 *
 *  - `ApplySaleToStockAction` berhenti memotong stok menunya dan mulai memotong BAHANNYA
 *    (lele, minyak) sesuai porsi terjual — kode itu sudah ada dan sudah teruji;
 *  - `SusunSisaStokAction::menuBerbasisResep()` menurunkan lencana "Habis"/"Menipis" di
 *    layar kasir dari keadaan bahan terparah, jadi kasir berhenti menawarkan lele goreng
 *    saat lelenya habis — juga sudah ada;
 *  - `SusunBarisStokAction` mengeluarkan menunya dari daftar Stok, karena yang habis
 *    bahannya, bukan menunya.
 *
 * Nol baris kode baru untuk ketiganya. Yang kurang selama ini cuma pintu untuk mengisi
 * resepnya.
 *
 * KENAPA layar sendiri, bukan panel di formulir produk. Formulir produk sudah kisi tiga
 * kolom yang padat dan memikul batas yang dimenangkan susah payah: panelnya tidak boleh
 * menggulir di 768px. Daftar baris yang bisa tumbuh sampai lima bahan pasti melanggarnya.
 * Resep juga bukan sesuatu yang dibaca sambil memindai daftar produk — ia diisi SEKALI
 * saat menyiapkan menu lalu jarang disentuh lagi, jadi yang penting kejelasannya, bukan
 * kedekatannya dengan formulir produk.
 */
#[Layout('layouts.aplikasi')]
class Resep extends Component
{
    use MengirimToast, TerikatTenant, WithPagination;

    /** Batas atas jumlah per porsi; penahan terakhir salah ketik 1000×. */
    public const MAKS_PER_PORSI = 1000;

    #[Url(as: 'cari')]
    public string $cari = '';

    /** semua|ada|belum — "belum" adalah jawaban "saya harus mengerjakan apa" untuk layar ini. */
    #[Url(as: 'saring')]
    public string $saring = 'semua';

    public bool $panel = false;

    /**
     * Menu yang resepnya sedang disusun.
     *
     * #[Locked] karena ia penentu TUJUAN penyimpanan: tanpa itu, muatan Livewire yang
     * ditukar membuat resep "Lele Goreng" tertulis ke "Es Teh" — dua-duanya menu milik
     * tenant yang sama, jadi tidak ada satu pun pemeriksaan yang menolak dan tidak ada
     * galat yang muncul. Pola yang sama dengan Bahan::$bahanId dan Opname::$outletId.
     */
    #[Locked]
    public ?string $produkId = null;

    #[Locked]
    public string $namaMenu = '';

    /**
     * Baris resep yang sedang diedit: [['bahan' => uuid, 'jumlah' => '0,25'], …].
     *
     * Sengaja larik biasa, bukan model: seluruh resep disimpan sekali lewat satu tombol,
     * jadi baris yang belum disimpan tidak boleh punya jejak apa pun di basis data.
     */
    public array $baris = [];

    /**
     * Peringatan giliran pertama: menu ini masih punya sisa stok tercatat.
     *
     * Diisi saat panel dibuka, bukan dihitung di Blade — angkanya harus sudah tetap saat
     * orang membaca kalimatnya.
     *
     * @var array<int, string>
     */
    #[Locked]
    public array $stokMenggantung = [];

    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'saring'], true)) {
            $this->resetPage();
        }
    }

    /* ── Panel ───────────────────────────────────────────────────────────── */

    public function atur(string $produkId): void
    {
        $produk = Product::query()->with('recipeItems')->findOrFail($produkId);

        $this->produkId = $produk->getKey();
        $this->namaMenu = $produk->nama_produk;

        $this->baris = $produk->recipeItems
            ->map(fn (RecipeItem $r) => [
                'bahan' => $r->raw_material_id,
                // Ditampilkan dengan koma: itu cara orang di sini menulis desimal, dan
                // kotaknya memang menerima koma.
                'jumlah' => rtrim(rtrim(number_format((float) $r->jumlah_terpakai, 3, ',', ''), '0'), ','),
            ])
            ->values()
            ->all();

        if ($this->baris === []) {
            $this->baris = [['bahan' => '', 'jumlah' => '']];
        }

        $this->stokMenggantung = $produk->usesRecipe() ? [] : $this->sisaTercatat($produk);
        $this->panel = true;
        $this->resetValidation();
    }

    public function tambahBaris(): void
    {
        $this->baris[] = ['bahan' => '', 'jumlah' => ''];
    }

    /**
     * Membuang satu baris dari formulir.
     *
     * Baris yang BELUM tersimpan dibuang tanpa dialog — tidak ada yang hilang, dan dialog
     * untuk membatalkan ketikan sendiri hanya melatih orang menekan "Ya" tanpa membaca.
     * Yang berdialog adalah menyimpan resep yang MENGHILANGKAN bahan (lihat Blade).
     */
    public function buangBaris(int $indeks): void
    {
        unset($this->baris[$indeks]);

        $this->baris = array_values($this->baris);

        if ($this->baris === []) {
            $this->baris = [['bahan' => '', 'jumlah' => '']];
        }

        $this->resetValidation();
    }

    public function tutupPanel(): void
    {
        $this->panel = false;
        $this->produkId = null;
        $this->namaMenu = '';
        $this->baris = [];
        $this->stokMenggantung = [];
        $this->resetValidation();
    }

    /* ── Simpan ──────────────────────────────────────────────────────────── */

    public function simpan(): void
    {
        $produk = Product::findOrFail($this->produkId);

        $bersih = $this->periksa();

        if ($bersih === null) {
            // Galatnya sudah menempel di baris yang salah; panelnya sengaja TETAP terbuka
            // supaya isian yang sudah diketik tidak hilang.
            return;
        }

        // Seluruh resep ditulis ulang dalam satu transaksi: menyimpan sebagian berarti
        // menu yang stoknya dipotong dari bahan yang tidak lengkap, dan itu meleleh
        // pelan-pelan tanpa ada yang menyadarinya.
        RecipeItem::query()->where('product_id', $produk->getKey())->delete();

        foreach ($bersih as $baris) {
            RecipeItem::create([
                'product_id' => $produk->getKey(),
                'raw_material_id' => $baris['bahan'],
                'jumlah_terpakai' => $baris['jumlah'],
            ]);
        }

        $this->panel = false;
        $this->produkId = null;
        $this->baris = [];
        $this->stokMenggantung = [];

        $this->toast($bersih === []
            ? 'Resep '.$produk->nama_produk.' dikosongkan. Stoknya dihitung sebagai barang jadi lagi.'
            : 'Resep '.$produk->nama_produk.' tersimpan.');
    }

    /**
     * Memeriksa baris resep dan mengembalikannya dalam bentuk yang siap disimpan.
     *
     * Mengembalikan `null` kalau ada yang salah — BUKAN melempar. Galatnya sengaja
     * ditempelkan ke baris yang bersangkutan (`baris.2.jumlah`), karena ringkasan galat di
     * puncak panel tidak memberi tahu baris KE BERAPA yang salah, dan resep berlima bahan
     * membuat orang menebak-nebak.
     *
     * @return list<array{bahan: string, jumlah: float}>|null
     */
    private function periksa(): ?array
    {
        $this->resetValidation();

        $milikTenant = RawMaterial::query()->pluck('nama', 'id');
        $bersih = [];
        $sudahDipakai = [];
        $galat = [];

        foreach ($this->baris as $i => $baris) {
            $bahan = (string) ($baris['bahan'] ?? '');
            $jumlahMentah = trim((string) ($baris['jumlah'] ?? ''));

            // Baris yang KEDUA-DUANYA kosong dianggap belum diisi, bukan salah: orang
            // menekan "Tambah bahan" lalu berubah pikiran, dan menolak simpan karenanya
            // memaksa ia mencari tombol buang untuk sesuatu yang tidak ia isi.
            if ($bahan === '' && $jumlahMentah === '') {
                continue;
            }

            if ($bahan === '' || ! $milikTenant->has($bahan)) {
                $galat["baris.{$i}.bahan"] = 'Pilih bahannya dulu.';

                continue;
            }

            if (isset($sudahDipakai[$bahan])) {
                // Ditolak di aplikasi, BUKAN dibiarkan ke unique index (product_id,
                // raw_material_id): galat basis data di layar berarti pemilik warung
                // membaca kalimat Inggris berisi nama kolom.
                $galat["baris.{$i}.bahan"] = $milikTenant[$bahan].' sudah ada di resep ini.';

                continue;
            }

            $jumlah = $this->jumlahAman($jumlahMentah);

            if ($jumlah === null) {
                $galat["baris.{$i}.jumlah"] = 'Tulis jumlahnya dengan angka — pakai koma kalau pecahan, mis. 0,25.';

                continue;
            }

            if ($jumlah <= 0) {
                // Nol berarti "bahan ini tidak dipakai", dan cara menyatakannya adalah
                // membuang barisnya — bukan menyimpan angka yang tidak berarti apa-apa.
                $galat["baris.{$i}.jumlah"] = 'Jumlahnya harus lebih dari nol. Kalau bahannya tidak dipakai, buang barisnya.';

                continue;
            }

            if ($jumlah > self::MAKS_PER_PORSI) {
                $galat["baris.{$i}.jumlah"] = 'Kelewat banyak untuk satu porsi — periksa lagi angkanya.';

                continue;
            }

            $sudahDipakai[$bahan] = true;
            $bersih[] = ['bahan' => $bahan, 'jumlah' => $jumlah];
        }

        if ($galat !== []) {
            foreach ($galat as $kunci => $pesan) {
                $this->addError($kunci, $pesan);
            }

            $this->toast(count($galat).' baris resep harus dibetulkan dulu.', 'galat');

            return null;
        }

        return $bersih;
    }

    /**
     * Membaca angka jumlah TANPA melempar.
     *
     * `Uang::bacaJumlah()` melempar InvalidArgumentException untuk bentuk yang menebak
     * ("1.500"), dan itu benar untuk aksi yang menyentuh stok — tapi salah untuk dua tempat
     * di layar ini:
     *
     *  - `periksa()` harus mengubahnya jadi pesan yang bisa dikerjakan, bukan halaman galat;
     *  - Blade menghitung gema "10 porsi = …" pada SETIAP ketikan, jadi memanggil yang
     *    melempar di sana membuat layarnya jatuh tepat saat orang sedang salah ketik —
     *    persis saat ia paling butuh melihat pesannya.
     *
     * Dipublikkan supaya Blade memanggil yang ini, bukan Uang langsung.
     */
    public function jumlahAman(mixed $nilai): ?float
    {
        try {
            return Uang::bacaJumlah($nilai);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /* ── Data layar ──────────────────────────────────────────────────────── */

    /**
     * Sisa stok menu ini yang masih tercatat, per outlet.
     *
     * Dipakai untuk peringatan giliran pertama. Begitu resepnya diisi, angka-angka ini
     * BERHENTI dihitung tanpa pernah menjadi nol — dan tetap terhitung di nilai
     * persediaan, padahal barangnya sudah tidak dilacak lagi.
     *
     * @return array<int, string>
     */
    private function sisaTercatat(Product $produk): array
    {
        return Stock::query()
            ->with('outlet')
            ->where('product_id', $produk->getKey())
            ->where('jumlah_saat_ini', '!=', 0)
            ->get()
            ->map(fn (Stock $s) => trim(rtrim(rtrim(number_format((float) $s->jumlah_saat_ini, 3, ',', '.'), '0'), ','))
                .' '.($produk->satuan?->value ?? '').' di '.($s->outlet?->outlet_name ?? 'outlet'))
            ->all();
    }

    private function kueriMenu(): Builder
    {
        return Product::query()
            ->with(['recipeItems.rawMaterial'])
            ->when($this->cari !== '', fn (Builder $q) => $q->where('nama_produk', 'like', '%'.$this->cari.'%'))
            ->when($this->saring === 'ada', fn (Builder $q) => $q->whereHas('recipeItems'))
            ->when($this->saring === 'belum', fn (Builder $q) => $q->whereDoesntHave('recipeItems'))
            ->orderBy('nama_produk');
    }

    public function render()
    {
        return view('livewire.pages.owner.bahan.resep', [
            'daftar' => $this->kueriMenu()->paginate(config('nampan.per_halaman')),
            'bahanTersedia' => RawMaterial::query()->orderBy('nama')->get(),
            'jumlahSemua' => Product::query()->count(),
            'jumlahAda' => Product::query()->whereHas('recipeItems')->count(),
            'jumlahBelum' => Product::query()->whereDoesntHave('recipeItems')->count(),
        ]);
    }
}
