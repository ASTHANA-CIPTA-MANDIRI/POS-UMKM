<?php

namespace App\Livewire\Pages\Owner;

use App\Actions\Stock\SiapkanBarisStokAction;
use App\Actions\Stock\SusunBarisStokAction;
use App\Livewire\Concerns\MengirimToast;
use App\Livewire\Concerns\TerikatTenant;
use App\Models\Outlet;
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

    /** semua|minus|habis|menipis|aman|perlu_diperiksa */
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
    }

    public function tutupKartu(): void
    {
        $this->kartuKunci = null;
    }

    /**
     * 30 mutasi terakhir baris yang kartunya dibuka.
     *
     * Dibatasi 30 dengan sengaja: yang dibutuhkan saat menjelaskan selisih adalah
     * pergerakan terakhir, dan memuat seluruh riwayat satu barang laris berarti ribuan
     * baris penjualan yang membuat halaman berhenti terpakai.
     *
     * @return Collection<int, StockMovement>
     */
    private function kartu(?array $baris): Collection
    {
        if ($baris === null || $baris['stock_id'] === null) {
            return collect();
        }

        return StockMovement::query()
            ->where('stock_id', $baris['stock_id'])
            ->with('olehUser:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get();
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

        if (in_array($this->status, ['minus', 'habis', 'menipis', 'aman'], true)) {
            return $baris->filter(fn (array $b) => $b['status'] === $this->status)->values();
        }

        return $baris;
    }

    /**
     * Blok "Harus belanja": semua yang statusnya bukan aman, paling parah dulu.
     *
     * Urutannya `jumlah − ambang` menaik, bukan kekurangan menurun: yang minus paling
     * dalam harus berada di baris pertama, dan barang tanpa ambang (kekurangan 0) tetap
     * ikut ditampilkan kalau saldonya habis — rak kosong tetap rak kosong walaupun tidak
     * ada ambang yang disetel untuknya.
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return Collection<int, array<string, mixed>>
     */
    private function harusBelanja(Collection $baris): Collection
    {
        return $baris
            ->filter(fn (array $b) => $b['status'] !== 'aman')
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
    private function halamankan(Collection $baris, int $perHalaman = 25): LengthAwarePaginator
    {
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

    public function render()
    {
        $baris = $this->baris();
        $tersaring = $this->saring($baris);
        $barisKartu = $this->kartuKunci === null ? null : $baris->firstWhere('kunci', $this->kartuKunci);

        return view('livewire.pages.owner.stok', [
            'daftar' => $this->halamankan($tersaring),
            'harusBelanja' => $this->harusBelanja($baris),
            'ringkasan' => [
                'minus' => $baris->where('status', 'minus')->count(),
                'habis' => $baris->where('status', 'habis')->count(),
                'menipis' => $baris->where('status', 'menipis')->count(),
                'aman' => $baris->where('status', 'aman')->count(),
                'perlu_diperiksa' => $baris->where('perlu_diperiksa', true)->count(),
            ],
            'barisKartu' => $barisKartu,
            'kartu' => $this->kartu($barisKartu),
            'outletTersedia' => auth()->user()->scopedOutletId() === null ? $this->outletTersedia() : [],
            'outletDipakai' => $this->outletTerpakai(),
        ]);
    }
}
