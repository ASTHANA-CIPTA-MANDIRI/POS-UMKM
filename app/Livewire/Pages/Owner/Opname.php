<?php

namespace App\Livewire\Pages\Owner;

use App\Actions\Stock\CatatOpnameAction;
use App\Actions\Stock\SusunBarisStokAction;
use App\Enums\AlasanOpname;
use App\Livewire\Concerns\MengirimToast;
use App\Livewire\Concerns\TerikatTenant;
use App\Models\Outlet;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lembar hitung fisik (stok opname) satu outlet.
 *
 * Barisnya berasal dari sumber yang SAMA dengan layar stok (SusunBarisStokAction),
 * termasuk barang yang belum punya baris `stocks` sama sekali — barang itu tampil dengan
 * sistem 0, dan justru barang itulah yang paling butuh dihitung.
 *
 * YANG PALING MUDAH DILEWATKAN DI HALAMAN INI, dan kenapa ada ujinya:
 *
 * Angka yang sudah diketik WAJIB bertahan saat pindah halaman dan saat saringan berubah,
 * dan simpan() memproses SEMUA baris terisi — bukan hanya baris yang sedang tampak.
 * Di layar semuanya terlihat baik-baik saja: angkanya masih ada di kolomnya sampai
 * halaman berganti. Kalau nilainya hilang atau simpan() hanya membaca halaman yang
 * tampak, pemilik menghitung 80 barang lalu kehilangan setengahnya tanpa satu pun galat
 * — dan ia tidak akan mencoba lagi.
 *
 * Karena itu $fisik/$alasan/$catatan di-key id barang (bukan indeks baris halaman) dan
 * tidak pernah di-reset oleh saringan atau paginasi.
 */
#[Layout('layouts.aplikasi')]
class Opname extends Component
{
    use MengirimToast, TerikatTenant, WithPagination;

    private const NAMA_HALAMAN = 'page';

    #[Url(as: 'outlet')]
    public ?string $outletId = null;

    #[Url(as: 'cari')]
    public string $cari = '';

    /** semua|produk|bahan */
    #[Url(as: 'jenis')]
    public string $jenis = 'semua';

    /** semua|minus|habis|menipis|aman|perlu_diperiksa|belum_pernah */
    #[Url(as: 'status')]
    public string $status = 'semua';

    /** @var array<string, mixed> jumlah fisik hasil hitung, di-key id barang */
    public array $fisik = [];

    /** @var array<string, string> kode AlasanOpname per barang */
    public array $alasan = [];

    /** @var array<string, string> catatan bebas per barang */
    public array $catatan = [];

    /**
     * Angka sistem yang TERBACA DI LAYAR saat fisik diketik, per barang.
     *
     * Bukan dasar perhitungan selisih — itu selalu saldo saat simpan, di dalam lock.
     * Gunanya satu: kalau saldonya bergerak selama penghitungan (kasir tetap berjualan),
     * catatan mutasi bisa memuat KEDUA angka, sehingga kartu stok bisa menjelaskan
     * selisih yang bukan salah penghitungnya.
     *
     * #[Locked] WAJIB di sini, dan alasannya bukan kerapian.
     *
     * Nilainya HANYA diisi server, di updatedFisik() dan saat baris dirender — perhatikan
     * `??=` di kedua tempat: yang tercatat adalah angka yang benar-benar pernah tampil,
     * dan hanya yang pertama. Tanpa #[Locked], properti publik ini bisa diganti lewat
     * muatan pembaruan Livewire, memotong Alpine sepenuhnya; QA membuktikannya dengan
     * mengirim 999 pada baris yang layarnya hanya pernah menunjukkan 10, dan catatan kartu
     * stok jadi berbunyi "layar menunjukkan 999, saldo saat disimpan 10" — angka yang tidak
     * pernah dirender kepada siapa pun.
     *
     * Angka stoknya sendiri tidak terpengaruh (selisihnya tetap fisik − saldo saat simpan),
     * jadi yang rusak bukan uang melainkan JEJAK AUDITNYA: satu-satunya keterangan yang
     * menjelaskan kenapa saldo bergerak selama penghitungan, bisa dikarang oleh orang yang
     * sedang dijelaskan oleh keterangan itu. Jejak audit yang bisa dipalsukan oleh pihak
     * yang diaudit sama saja dengan tidak ada.
     *
     * @var array<string, float>
     */
    #[Locked]
    public array $sistemSaatDibuka = [];

    /**
     * Sebagian baris tersimpan sementara sebagian gagal, pada percobaan simpan terakhir.
     *
     * Dipakai Blade untuk memilih kalimat penjelas di blok ringkasan galat. Blok itu
     * lahir untuk galat validasi, yang menahan SELURUH lembar — kalimatnya berbunyi "tidak
     * ada satu baris pun yang tersimpan". Untuk kegagalan sesudah simpan, kalimat itu
     * menjadi keterangan yang salah tentang data yang sudah berubah, dan pemiliknya bisa
     * menghitung ulang barang yang sebenarnya sudah tercatat.
     */
    public bool $sebagianTersimpan = false;

    public function mount(): void
    {
        if (blank($this->outletId)) {
            $this->outletId = $this->outletBawaan();
        }
    }

    /**
     * Saringan & paginasi TIDAK PERNAH menyentuh $fisik/$alasan/$catatan.
     *
     * Kalau suatu hari ada yang menambahkan reset di sini "supaya bersih", 80 baris hasil
     * hitung akan hilang begitu kolom cari diketik. Ujinya ada supaya itu tertangkap.
     */
    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'jenis', 'status', 'outletId'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Rekam angka sistem yang sedang terbaca saat baris ini pertama kali diketik.
     *
     * Cadangan untuk ikatan `.live`/`.blur`; jalur utamanya adalah rekaman saat render
     * (lihat rekamAngkaTerbaca) — `wire:model` biasa bersifat tertunda, nilainya baru
     * tiba di server saat tombol simpan ditekan, dan saat itu saldo sistem sudah bergerak.
     */
    public function updatedFisik(mixed $nilai, ?string $kunci): void
    {
        if ($kunci === null) {
            return;
        }

        if (blank($nilai)) {
            unset($this->sistemSaatDibuka[$kunci]);

            return;
        }

        // ??= — yang disimpan adalah angka saat PERTAMA kali terbaca. Pengetikan ulang
        // (ganti 7 jadi 8) tidak boleh menghapus jejak angka yang dilihat penghitungnya.
        $this->sistemSaatDibuka[$kunci] ??= (float) ($this->semuaBaris()->firstWhere('kunci', $kunci)['sistem'] ?? 0);
    }

    /**
     * Menyimpan angka sistem yang BENAR-BENAR DITAMPILKAN untuk tiap baris yang dirender.
     *
     * Kenapa di render dan bukan hanya saat diketik: `wire:model` bawaan Livewire
     * bersifat tertunda — angka yang diketik pemilik baru tiba di server bersamaan dengan
     * penekanan tombol simpan. Kalau angka sistem baru dibaca saat itu, ia sudah sama
     * dengan saldo terbaru, dan pergerakan yang terjadi selama penghitungan tidak pernah
     * tercatat di catatan mutasi — padahal itu satu-satunya keterangan yang membedakan
     * "penghitungnya salah" dari "barangnya terjual sesudah dihitung".
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     */
    private function rekamAngkaTerbaca(Collection $baris): void
    {
        foreach ($baris as $satu) {
            $this->sistemSaatDibuka[$satu['kunci']] ??= (float) $satu['sistem'];
        }
    }

    /* ── Outlet ──────────────────────────────────────────────────────────── */

    /** Lihat Stok::outletTerpakai() — pemeriksaan aksesnya dijalankan tiap render. */
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

    /* ── Simpan ──────────────────────────────────────────────────────────── */

    /**
     * Menyimpan seluruh lembar.
     *
     * Divalidasi LEBIH DULU untuk semua baris terisi, baru dicatat. Kalau satu baris
     * ditolak, tidak boleh ada baris lain yang sudah tersimpan separuh jalan: pemilik
     * yang melihat pesan galat akan mengulang, dan baris yang sudah masuk akan dihitung
     * dua kali.
     */
    public function simpan(): void
    {
        // Dinolkan di awal SETIAP percobaan: penanda dari percobaan sebelumnya akan
        // membuat kalimat penjelasnya berbicara tentang penyimpanan yang sudah lewat.
        $this->sebagianTersimpan = false;

        $outletId = $this->outletTerpakai();

        if ($outletId === null) {
            $this->toast('Pilih outlet dulu sebelum menyimpan hasil hitung.', 'peringatan');

            return;
        }

        // Sumber baris TANPA saringan: yang disimpan adalah semua yang diketik, bukan
        // yang sedang tampak di layar.
        $semua = $this->semuaBaris()->keyBy('kunci');
        $terisi = collect($this->fisik)->filter(fn (mixed $nilai) => $this->diisi($nilai));

        if ($terisi->isEmpty()) {
            $this->toast('Belum ada jumlah fisik yang diisi.', 'peringatan');

            return;
        }

        $this->periksa($terisi, $semua);

        $muatan = $terisi->map(fn (mixed $nilai, string $kunci) => [
            'product_id' => $semua[$kunci]['product_id'],
            'raw_material_id' => $semua[$kunci]['raw_material_id'],
            'fisik' => $nilai,
            'alasan' => $this->alasan[$kunci] ?? null,
            'catatan' => $this->catatan[$kunci] ?? null,
            'sistem_saat_dibuka' => $this->sistemSaatDibuka[$kunci] ?? null,
        ])->values()->all();

        $ringkasan = app(CatatOpnameAction::class)->execute(
            Outlet::query()->findOrFail($outletId),
            auth()->user(),
            $muatan,
        );

        $gagal = collect($ringkasan['gagal'])
            ->map(fn (array $baris) => $baris['product_id'] ?? $baris['raw_material_id'])
            ->filter()
            ->all();

        // Baris yang gagal DIBIARKAN terisi supaya bisa dicoba lagi tanpa dihitung ulang;
        // baris yang berhasil dibersihkan supaya tidak tersimpan dua kali.
        foreach ($terisi->keys() as $kunci) {
            if (in_array($kunci, $gagal, true)) {
                continue;
            }

            unset($this->fisik[$kunci], $this->alasan[$kunci], $this->catatan[$kunci], $this->sistemSaatDibuka[$kunci]);
        }

        $pesan = $ringkasan['dihitung'].' barang dihitung, '.$ringkasan['mutasi'].' selisih tercatat.';

        if ($ringkasan['gagal'] !== []) {
            /*
             * Sebabnya per baris DITERUSKAN, bukan cuma dihitung.
             *
             * Dulu pesannya hanya "1 baris gagal disimpan dan masih terisi" — tanpa nama
             * barang, tanpa sebab. Pemilik yang menghitung 120 barang menghadapi 12 halaman
             * (10 baris per halaman), jadi ia harus membuka halaman satu per satu mencari
             * baris mana yang masih terisi, tanpa tahu kenapa. Padahal CatatOpnameAction
             * sudah menyerahkan kalimat penuhnya di $ringkasan['gagal'][*]['pesan'];
             * keterangan itu dibuang di sini.
             *
             * Dititipkan ke $errors, bukan hanya ke toast: blok ringkasan galat di Blade
             * memasangkan kunci baris dengan NAMA barangnya dan tampil walau barisnya
             * sedang berada di halaman lain — sementara toast hilang begitu ditutup dan
             * penanda per baris tidak ada gunanya kalau barisnya tidak sedang dirender.
             */
            foreach ($ringkasan['gagal'] as $baris) {
                $kunci = $baris['product_id'] ?? $baris['raw_material_id'];

                if ($kunci !== null) {
                    $this->addError('fisik.'.$kunci, $baris['pesan']);
                }
            }

            // Blok ringkasan itu biasanya dipakai untuk galat validasi, yang menahan SELURUH
            // penyimpanan. Di sini sebagian baris sudah benar-benar tersimpan, jadi kalimat
            // "tidak ada satu baris pun yang tersimpan" akan menjadi keterangan yang salah
            // tentang data yang sudah berubah — dan pemilik bisa menghitung ulang barang
            // yang sebenarnya sudah tercatat.
            $this->sebagianTersimpan = $ringkasan['dihitung'] > 0;

            $this->toast($pesan.' '.count($ringkasan['gagal']).' baris gagal dan masih terisi — sebabnya di daftar atas.', 'peringatan');

            return;
        }

        $this->toast($pesan);
    }

    /**
     * Validasi seluruh baris terisi sekaligus.
     *
     * @param  Collection<string, mixed>  $terisi
     * @param  Collection<string, array<string, mixed>>  $semua
     */
    private function periksa(Collection $terisi, Collection $semua): void
    {
        $aturan = [];
        $atribut = [];

        foreach ($terisi->keys() as $kunci) {
            // Fisik tidak boleh negatif: rak tidak bisa berisi minus tiga. Angka minus di
            // kolom ini selalu salah ketik, dan menerimanya mengarang selisih dua kali.
            $aturan['fisik.'.$kunci] = ['numeric', 'min:0', 'max:99999999'];
            $atribut['fisik.'.$kunci] = 'jumlah fisik';
            $atribut['alasan.'.$kunci] = 'alasan selisih';
            $atribut['catatan.'.$kunci] = 'catatan';
        }

        $validator = Validator::make(
            ['fisik' => $this->fisik, 'alasan' => $this->alasan, 'catatan' => $this->catatan],
            $aturan,
            [],
            $atribut,
        );

        $validator->after(function ($validator) use ($terisi, $semua): void {
            foreach ($terisi as $kunci => $nilai) {
                if (! is_numeric($nilai)) {
                    continue;
                }

                $baris = $semua->get($kunci);

                if ($baris === null) {
                    // Barangnya dihapus/dinonaktifkan pelacakannya saat lembar terbuka.
                    // Dilaporkan, bukan dilewati diam-diam: angka yang sudah dihitung
                    // orang tidak boleh hilang tanpa pemberitahuan.
                    $validator->errors()->add('fisik.'.$kunci, 'Barang ini tidak ada lagi di daftar stok outlet ini.');

                    continue;
                }

                $selisih = round((float) $nilai - (float) $baris['sistem'], 3);
                $alasan = trim((string) ($this->alasan[$kunci] ?? ''));

                if (abs($selisih) > 0.0005 && $alasan === '') {
                    // Selisih tanpa alasan adalah angka yang tidak bisa ditindaklanjuti:
                    // sebulan kemudian tidak ada yang bisa menjawab "berapa yang rusak,
                    // berapa yang hilang" — dan itu satu-satunya gunanya mencatat selisih.
                    $validator->errors()->add('alasan.'.$kunci, 'Alasan selisih "'.$baris['nama'].'" wajib dipilih.');

                    continue;
                }

                if ($alasan === '') {
                    continue;
                }

                $kode = AlasanOpname::tryFrom($alasan);

                if ($kode === null) {
                    $validator->errors()->add('alasan.'.$kunci, 'Alasan selisih tidak dikenali.');

                    continue;
                }

                if ($kode->butuhCatatan() && trim((string) ($this->catatan[$kunci] ?? '')) === '') {
                    $validator->errors()->add('catatan.'.$kunci, 'Catatan wajib diisi kalau alasannya "'.$kode->label().'".');
                }
            }
        });

        // Melempar ValidationException — Livewire menangkapnya dan mengisi kantong galat,
        // dan tidak ada satu baris pun yang tersimpan sebelum ini lolos.
        $validator->validate();
    }

    /* ── Daftar ──────────────────────────────────────────────────────────── */

    /** @return Collection<int, array<string, mixed>> */
    /**
     * Nama barang per kunci, untuk blok ringkasan galat.
     *
     * Halaman yang sedang tampak TIDAK cukup, dan itu justru inti masalahnya. Blok
     * ringkasan ada supaya baris bermasalah di halaman lain tetap terlihat; kalau namanya
     * diambil dari baris yang sedang dirender saja, baris di halaman 3 tampil sebagai
     * "Baris lain" — pemiliknya tahu ada yang salah tapi tetap tidak tahu barang apa, yaitu
     * keadaan yang sama dengan tidak diberi tahu. Dengan 10 baris per halaman, lembar 120
     * barang punya 12 halaman, jadi keadaan ini normal, bukan pengecualian.
     *
     * Baris seluruh outlet hanya diambil kalau memang ADA galat — pada penyimpanan yang
     * lancar (jalur yang biasa terjadi) tidak ada kueri tambahan sama sekali.
     *
     * @param  array<int, array<string, mixed>>  $halamanIni
     * @return Collection<string, string>
     */
    private function namaPerKunci(array $halamanIni): Collection
    {
        $nama = collect($halamanIni)->pluck('nama', 'kunci');

        if (! $this->getErrorBag()->isNotEmpty()) {
            return $nama;
        }

        $kurang = collect($this->getErrorBag()->keys())
            ->map(fn (string $kunci) => str($kunci)->after('.')->toString())
            ->reject(fn (string $kunci) => $nama->has($kunci));

        if ($kurang->isEmpty()) {
            return $nama;
        }

        return $nama->merge(
            $this->semuaBaris()
                ->filter(fn (array $b) => $kurang->contains($b['kunci']))
                ->pluck('nama', 'kunci')
        );
    }

    private function semuaBaris(): Collection
    {
        $outletId = $this->outletTerpakai();

        if ($outletId === null) {
            return collect();
        }

        return app(SusunBarisStokAction::class)->execute($outletId);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function barisTersaring(): Collection
    {
        $outletId = $this->outletTerpakai();

        if ($outletId === null) {
            return collect();
        }

        $baris = app(SusunBarisStokAction::class)->execute($outletId, $this->jenis, trim($this->cari));

        return match (true) {
            $this->status === 'perlu_diperiksa' => $baris->filter(fn (array $b) => $b['perlu_diperiksa'])->values(),
            $this->status === 'belum_pernah' => $baris->filter(fn (array $b) => $b['opname_terakhir_pada'] === null)->values(),
            in_array($this->status, ['minus', 'habis', 'menipis', 'aman'], true) => $baris
                ->filter(fn (array $b) => $b['status'] === $this->status)->values(),
            default => $baris,
        };
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

    /** Nol dianggap TERISI; hanya null/string kosong yang berarti belum dihitung. */
    private function diisi(mixed $nilai): bool
    {
        return $nilai !== null && $nilai !== '';
    }

    /** Berapa baris yang sudah diketik — termasuk yang tidak sedang tampak di layar. */
    public function jumlahTerisi(): int
    {
        return collect($this->fisik)->filter(fn (mixed $nilai) => $this->diisi($nilai))->count();
    }

    public function render()
    {
        $daftar = $this->halamankan($this->barisTersaring());

        $this->rekamAngkaTerbaca(collect($daftar->items()));

        return view('livewire.pages.owner.opname', [
            'daftar' => $daftar,
            'namaPerKunci' => $this->namaPerKunci($daftar->items()),
            'alasanTersedia' => AlasanOpname::pilihan(),
            'jumlahTerisi' => $this->jumlahTerisi(),
            'outletTersedia' => auth()->user()->scopedOutletId() === null ? $this->outletTersedia() : [],
            'outletDipakai' => $this->outletTerpakai(),
        ]);
    }
}
