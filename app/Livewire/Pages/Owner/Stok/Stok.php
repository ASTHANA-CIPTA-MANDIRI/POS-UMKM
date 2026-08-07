<?php

namespace App\Livewire\Pages\Owner\Stok;

use App\Actions\Stock\SiapkanBarisStokAction;
use App\Actions\Stock\SusunBarisStokAction;
use App\Livewire\Concerns\MengirimToast;
use App\Livewire\Concerns\TerikatTenant;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Layar stok satu outlet: produk yang dilacak + bahan baku, apa adanya.
 *
 * Tiga hal yang membuat layar ini bukan sekadar tabel angka:
 *
 * 1. **Blok "Harus belanja"** — barang yang statusnya bukan `aman`, diurutkan dari yang
 *    paling parah (jumlah − ambang paling kecil), dengan jumlah yang harus dibeli dalam
 *    satuan dasar DAN satuan beli. Daftar stok yang cuma menampilkan angka menyerahkan
 *    pekerjaan pengurangan ke pemiliknya, dan pekerjaan itu tidak akan dilakukan di
 *    tengah kesibukan warung.
 *
 * 2. **Ambang minimum bisa disetel di sini** — dan ini SATU-SATUNYA tempat ambang bahan
 *    baku bisa disetel (formulir produk hanya mengurus produk). Tanpa ambang, tidak ada
 *    satu pun barang yang bisa disebut "kurang", dan blok harus-belanja tinggal kosong
 *    selamanya tanpa penjelasan.
 *
 * 3. **Kartu stok per baris** — 30 mutasi terakhir. Tanpa ini, opname jadi fitur
 *    tulis-saja: angkanya berubah dan tidak ada seorang pun yang bisa menjawab kenapa.
 */
#[Layout('layouts.aplikasi')]
class Stok extends Component
{
    use MengirimToast, TerikatTenant, WithPagination;

    private const NAMA_HALAMAN = 'page';

    /*
     * Kartu stok punya nomor halamannya SENDIRI.
     *
     * Kalau ikut 'page', membuka riwayat mutasi di halaman 3 daftar akan melompatkan
     * daftarnya, dan menggeser halaman riwayat akan menggeser daftarnya juga — dua daftar
     * berbeda memakai satu penunjuk.
     */
    private const HALAMAN_KARTU = 'kartu';

    /**
     * Outlet yang stoknya dilihat. Stok dicatat PER OUTLET, jadi layar ini selalu
     * bekerja atas satu outlet — tidak ada tampilan "gabungan semua cabang" di sini,
     * karena angka gabungan tidak bisa dibelanjakan maupun dihitung fisik.
     */
    #[Url(as: 'outlet')]
    public ?string $outletId = null;

    #[Url(as: 'cari')]
    public string $cari = '';

    /** semua|produk|bahan */
    #[Url(as: 'jenis')]
    public string $jenis = 'semua';

    /** semua|minus|habis|menipis|aman|belum_dihitung|perlu_diperiksa */
    #[Url(as: 'status')]
    public string $status = 'semua';

    /** Baris yang ambangnya sedang diubah inline; null berarti tidak ada. */
    public ?string $ambangKunci = null;

    public ?string $ambangNilai = null;

    /** Baris yang kartu stoknya sedang dibuka. */
    public ?string $kartuKunci = null;

    public function mount(): void
    {
        // Hanya diisi kalau URL tidak menyebutnya, supaya tautan yang dibagikan
        // (mis. ?outlet=…) tidak ditimpa oleh outlet bawaan.
        if (blank($this->outletId)) {
            $this->outletId = $this->outletBawaan();
        }
    }

    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'jenis', 'status', 'outletId'], true)) {
            $this->resetPage();
            $this->kartuKunci = null;
            $this->ambangKunci = null;
        }
    }

    /* ── Outlet ──────────────────────────────────────────────────────────── */

    /**
     * Outlet yang benar-benar dipakai — dan gerbang aksesnya.
     *
     * Dipanggil setiap render, bukan hanya saat mount: pemilihan outlet bisa berubah
     * kapan saja lewat properti, dan pemeriksaan yang hanya berjalan di mount berarti
     * outlet tenant lain cukup di-set belakangan untuk lolos.
     *
     * Peran yang terkunci ke satu outlet (manager outlet) selalu memakai outletnya
     * sendiri; nilai dari klien diabaikan sama sekali untuk peran itu.
     */
    public function outletTerpakai(): ?string
    {
        $user = auth()->user();
        $terkunci = $user->scopedOutletId();

        if ($terkunci !== null) {
            return $terkunci;
        }

        if (filled($this->outletId)) {
            abort_unless($user->canAccessOutlet($this->outletId), 403);

            return $this->outletId;
        }

        return null;
    }

    private function outletBawaan(): ?string
    {
        $terkunci = auth()->user()->scopedOutletId();

        return $terkunci ?? Outlet::query()->orderBy('outlet_name')->value('id');
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

    /* ── Ambang minimum inline ───────────────────────────────────────────── */

    public function ubahAmbang(string $kunci): void
    {
        $baris = $this->baris()->firstWhere('kunci', $kunci);

        if ($baris === null) {
            return;
        }

        $this->ambangKunci = $kunci;
        $this->ambangNilai = $baris['minimum'] > 0 ? $this->angkaRapi($baris['minimum']) : '';
        $this->resetValidation();
    }

    public function batalUbahAmbang(): void
    {
        $this->ambangKunci = null;
        $this->ambangNilai = null;
        $this->resetValidation();
    }

    /**
     * Menyimpan ambang minimum satu baris.
     *
     * Menyetel ambang TIDAK membuat mutasi stok — jumlahnya tidak berubah satu pun, jadi
     * mencatatnya di kartu stok berarti mengarang pergerakan barang yang tidak pernah
     * terjadi. Kartu stok adalah satu-satunya bukti yang tersisa kalau nanti ada selisih
     * yang harus dijelaskan, dan baris palsu di dalamnya merusaknya untuk selamanya.
     */
    public function simpanAmbang(): void
    {
        $outletId = $this->outletTerpakai();

        if ($outletId === null || $this->ambangKunci === null) {
            return;
        }

        $this->validate(
            ['ambangNilai' => ['nullable', 'numeric', 'min:0', 'max:99999999']],
            attributes: ['ambangNilai' => 'stok minimum'],
        );

        $baris = $this->baris()->firstWhere('kunci', $this->ambangKunci);

        if ($baris === null) {
            throw ValidationException::withMessages([
                'ambangNilai' => 'Barang ini tidak ada lagi di daftar stok outlet ini.',
            ]);
        }

        $ambang = filled($this->ambangNilai) ? (float) $this->ambangNilai : 0.0;

        $stok = $baris['stock_id'] !== null
            ? Stock::query()->find($baris['stock_id'])
            : null;

        // Ambang 0 pada barang yang belum punya baris stok tidak perlu membuat baris apa
        // pun: "tidak dipantau" memang keadaan bawaannya.
        if ($stok === null && $ambang <= 0) {
            $this->batalUbahAmbang();

            return;
        }

        $stok ??= app(SiapkanBarisStokAction::class)->execute(
            auth()->user()->tenant_id,
            $outletId,
            $baris['product_id'],
            $baris['raw_material_id'],
        );

        if ((float) $stok->stok_minimum !== $ambang) {
            $stok->stok_minimum = $ambang;
            $stok->save();
        }

        $this->batalUbahAmbang();

        $this->toast($ambang > 0
            ? 'Ambang minimum "'.$baris['nama'].'" disetel '.$this->angkaRapi($ambang).' '.($baris['satuan_dasar'] ?? '').'.'
            : 'Ambang minimum "'.$baris['nama'].'" dimatikan; barang ini tidak lagi masuk daftar belanja.');
    }

    /* ── Kartu stok ──────────────────────────────────────────────────────── */

    public function bukaKartu(string $kunci): void
    {
        $this->kartuKunci = $this->kartuKunci === $kunci ? null : $kunci;

        // Riwayatnya selalu mulai dari halaman 1. Tanpa ini, halaman 7 dari barang
        // sebelumnya terbawa ke barang yang riwayatnya hanya 3 baris, dan panelnya
        // terbuka KOSONG — terbaca sebagai "tidak ada mutasi", padahal ada.
        $this->resetPage(self::HALAMAN_KARTU);
    }

    public function tutupKartu(): void
    {
        $this->kartuKunci = null;
    }

    /**
     * Riwayat mutasi baris yang kartunya dibuka, berhalaman.
     *
     * Dulu `limit(30)`. Itu memotong riwayat tanpa memberi tahu, dan justru menghapus
     * jawaban yang dicari: pemilik membuka kartu stok untuk menelusuri KE BELAKANG, mencari
     * kapan angkanya mulai salah. Barang laris di kelontong bisa punya ratusan mutasi dalam
     * sebulan, jadi 30 baris terakhir membuatnya menyimpulkan tidak ada apa-apa sebelum itu.
     *
     * Kekhawatiran lama tetap sah — memuat ribuan baris sekaligus membuat halaman berhenti
     * terpakai — dan halaman menjawabnya lebih baik daripada memotong: barisnya tetap
     * sedikit per muatan, tapi tidak ada yang disembunyikan.
     *
     * @return LengthAwarePaginator<int, StockMovement>|Collection<int, StockMovement>
     */
    private function kartu(?array $baris): LengthAwarePaginator|Collection
    {
        if ($baris === null || $baris['stock_id'] === null) {
            return collect();
        }

        /*
         * Dihalamankan, bukan `limit(30)`.
         *
         * `limit` memotong riwayat TANPA memberi tahu: barang yang laku di kelontong bisa
         * punya ratusan mutasi dalam sebulan, dan pemilik yang mencari "kapan stok ini mulai
         * salah" melihat 30 baris terakhir lalu menyimpulkan tidak ada apa-apa sebelum itu.
         * Kartu stok gunanya justru menelusuri ke belakang; memotongnya diam-diam menghapus
         * satu-satunya jawaban yang dicari.
         *
         * Jumlah barisnya memakai setelan bersama supaya sama dengan seluruh daftar lain.
         */
        return StockMovement::query()
            ->where('stock_id', $baris['stock_id'])
            ->with('olehUser:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(config('nampan.per_halaman'), ['*'], self::HALAMAN_KARTU);
    }

    /* ── Daftar ──────────────────────────────────────────────────────────── */

    /**
     * Sumber baris — SATU tempat, dipakai bersama lembar opname.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function baris(): Collection
    {
        $outletId = $this->outletTerpakai();

        if ($outletId === null) {
            return collect();
        }

        return app(SusunBarisStokAction::class)->execute($outletId, $this->jenis, trim($this->cari));
    }

    /**
     * Saringan status dijalankan di PHP, bukan SQL.
     *
     * Status adalah keputusan Stock::statusStok() (urutannya: minus sebelum menipis), dan
     * menuliskannya ulang sebagai kondisi SQL berarti dua definisi untuk satu kenyataan.
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return Collection<int, array<string, mixed>>
     */
    private function saring(Collection $baris): Collection
    {
        if ($this->status === 'perlu_diperiksa') {
            return $baris->filter(fn (array $b) => $b['perlu_diperiksa'])->values();
        }

        // `belum_dihitung` ikut di sini, bukan jadi cabang sendiri: ia adalah nilai
        // `status` yang setara dengan yang lain, dan lencana yang bisa dilihat tapi tidak
        // bisa disaring adalah lencana yang tidak bisa ditindaklanjuti — pemilik yang
        // melihat "37 belum dihitung" harus bisa membuka daftar 37-nya.
        if (in_array($this->status, ['minus', 'habis', 'menipis', 'aman', 'belum_dihitung'], true)) {
            return $baris->filter(fn (array $b) => $b['status'] === $this->status)->values();
        }

        return $baris;
    }

    /**
     * Blok "Harus belanja": yang angkanya menyatakan kurang, paling parah dulu.
     *
     * Urutannya `jumlah − ambang` menaik, bukan kekurangan menurun: yang minus paling
     * dalam harus berada di baris pertama, dan barang tanpa ambang (kekurangan 0) tetap
     * ikut ditampilkan kalau saldonya habis — rak kosong tetap rak kosong walaupun tidak
     * ada ambang yang disetel untuknya.
     *
     * Statusnya disebut SATU-SATU, bukan `!== 'aman'`. Bedanya besar dan tidak kelihatan:
     * `belum_dihitung` juga bukan 'aman', jadi bentuk lama menariknya masuk ke sini. Blok
     * ini hanya menampilkan sembilan kartu dan diurut `sistem − minimum` menaik, sementara
     * barang belum-dihitung SELALU bernilai 0 pada kunci itu (tidak punya ambang) — maka di
     * warung yang baru memasukkan 300 barang, kesembilan slotnya habis dipakai barang yang
     * mungkin masih ada di rak, dan kartunya bahkan tidak bisa menyebut jumlah belanja
     * (`kekurangan` 0, `beli` null). Yang benar-benar kurang tersingkir dari layar oleh
     * barang yang belum pernah dihitung.
     *
     * Peringatannya tidak hilang, hanya pindah tempat: layar stok memasang spanduk
     * agregat "N barang belum pernah dihitung" dengan tautan ke lembar opname — tindakan
     * yang benar untuk barang tanpa angka adalah menghitungnya, bukan membelinya.
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return Collection<int, array<string, mixed>>
     */
    private function harusBelanja(Collection $baris): Collection
    {
        return $baris
            ->filter(fn (array $b) => in_array($b['status'], ['minus', 'habis', 'menipis'], true))
            ->sortBy([
                fn (array $a, array $b) => ($a['sistem'] - $a['minimum']) <=> ($b['sistem'] - $b['minimum']),
                fn (array $a, array $b) => strcasecmp($a['nama'], $b['nama']),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function halamankan(Collection $baris, int $perHalaman = 0): LengthAwarePaginator
    {
        // 0 = pakai setelan bersama. Angkanya tidak diketik ulang di sini supaya
        // seluruh daftar di aplikasi berpindah bersamaan kalau nanti diubah.
        $perHalaman = $perHalaman > 0 ? $perHalaman : (int) config('nampan.per_halaman');

        // Dipaginasi di PHP, bukan SQL: barisnya gabungan dua tabel (produk + bahan baku)
        // dan statusnya diputuskan oleh Stock::statusStok(), bukan oleh kondisi SQL. UNION
        // dengan aturan status yang ditulis ulang sebagai SQL akan menjadi definisi kedua
        // untuk hal yang sama — dan dua definisi berarti dua angka untuk satu kenyataan.
        $halaman = max(1, (int) $this->getPage(self::NAMA_HALAMAN));

        return new LengthAwarePaginator(
            $baris->forPage($halaman, $perHalaman)->values(),
            $baris->count(),
            $perHalaman,
            $halaman,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => self::NAMA_HALAMAN,
            ],
        );
    }

    private function angkaRapi(float $nilai): string
    {
        return rtrim(rtrim(number_format($nilai, 3, ',', '.'), '0'), ',');
    }

    /**
     * Nilai persediaan: jumlah × harga beli, dan JUMLAH BARANG YANG TIDAK BISA DIHITUNG.
     *
     * Angka ini yang paling dicari pemilik warung saat membuka layar stok — "berapa uang
     * saya yang sekarang berbentuk barang" — dan sebelumnya tidak ada di layar mana pun.
     *
     * Dua keputusan yang menjaganya tetap jujur:
     *
     * 1. Barang tanpa harga beli TIDAK dihitung nol diam-diam. Nol berarti "barangnya tidak
     *    ada nilainya", dan itu pernyataan yang lain sekali dari "harganya belum diisi".
     *    Jumlahnya dikembalikan terpisah supaya layar bisa menyebutnya apa adanya; tanpa itu
     *    pemilik membandingkan angka ini dengan uang belanjanya dan menyimpulkan
     *    pencatatannya kacau.
     * 2. Saldo minus tidak dijadikan uang negatif. Minus berarti PENCATATANNYA bermasalah
     *    (penjualan offline yang masuk dua kali), bukan bahwa raknya berutang barang — jadi
     *    barisnya dihitung nol untuk nilai, dan tetap terhitung di kartu "Minus & habis"
     *    yang mengajak memperbaikinya.
     *
     * Ikut saringan `cari`/`jenis`, sama seperti chip di atasnya: dua angka di satu layar
     * yang menghitung himpunan berbeda membuat orang berhenti memercayai keduanya.
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return array{nilai: float, tanpa_harga: int}
     */
    private function nilaiPersediaan(Collection $baris): array
    {
        $idProduk = $baris->where('jenis', 'produk')->pluck('product_id')->filter()->all();
        $idBahan = $baris->where('jenis', 'bahan')->pluck('raw_material_id')->filter()->all();

        $harga = collect();

        if ($idProduk !== []) {
            $harga = $harga->merge(
                Product::query()->whereKey($idProduk)->pluck('harga_beli', 'id'),
            );
        }

        if ($idBahan !== []) {
            $harga = $harga->merge(
                RawMaterial::query()->whereKey($idBahan)->pluck('harga_beli_terakhir', 'id'),
            );
        }

        $nilai = 0.0;
        $tanpaHarga = 0;

        foreach ($baris as $b) {
            $jumlah = max(0.0, (float) $b['sistem']);

            if (! $b['punya_baris'] || $jumlah <= 0.0) {
                continue;
            }

            $satuan = (float) ($harga[$b['kunci']] ?? 0);

            if ($satuan <= 0.0) {
                $tanpaHarga++;

                continue;
            }

            $nilai += $jumlah * $satuan;
        }

        return ['nilai' => $nilai, 'tanpa_harga' => $tanpaHarga];
    }

    public function render()
    {
        $baris = $this->baris();
        $tersaring = $this->saring($baris);
        $barisKartu = $this->kartuKunci === null ? null : $baris->firstWhere('kunci', $this->kartuKunci);

        return view('livewire.pages.owner.stok.stok', [
            'daftar' => $this->halamankan($tersaring),
            'harusBelanja' => $this->harusBelanja($baris),
            // `semua` diambil dari jumlah baris yang sebenarnya, BUKAN dari menjumlahkan
            // chip-chip di bawahnya. Penjumlahan manual sudah pernah membuat chip berkata
            // empat sementara tabelnya menampilkan lima, dan chip yang tidak cocok dengan
            // isi tabel membuat orang berhenti mempercayai keduanya. `perlu_diperiksa`
            // memang tidak boleh ikut dijumlahkan — ia bendera, bukan status, jadi satu
            // baris bisa terhitung di situ DAN di salah satu status.
            'ringkasan' => [
                'semua' => $baris->count(),
                'minus' => $baris->where('status', 'minus')->count(),
                'habis' => $baris->where('status', 'habis')->count(),
                'menipis' => $baris->where('status', 'menipis')->count(),
                'aman' => $baris->where('status', 'aman')->count(),
                'belum_dihitung' => $baris->where('status', 'belum_dihitung')->count(),
                'perlu_diperiksa' => $baris->where('perlu_diperiksa', true)->count(),
            ],
            'barisKartu' => $barisKartu,
            'kartu' => $this->kartu($barisKartu),
            'nilaiPersediaan' => $this->nilaiPersediaan($baris),
            'outletTersedia' => auth()->user()->scopedOutletId() === null ? $this->outletTersedia() : [],
            'outletDipakai' => $this->outletTerpakai(),
        ]);
    }
}
