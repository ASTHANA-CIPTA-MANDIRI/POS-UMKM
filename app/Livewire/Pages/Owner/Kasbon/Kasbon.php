<?php

namespace App\Livewire\Pages\Owner\Kasbon;

use App\Actions\Kasbon\CatatPelunasanAction;
use App\Enums\CreditStatus;
use App\Livewire\Concerns\MengirimToast;
use App\Livewire\Concerns\TerikatTenant;
use App\Models\Pelanggan\CreditLedger;
use App\Models\Pelanggan\CreditPayment;
use App\Models\Pelanggan\Customer;
use App\Support\Uang;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * Buku kasbon: siapa berutang berapa, dan setoran apa saja yang sudah masuk.
 *
 * KENAPA LAYAR INI PENTING melebihi tampilannya. Kasbon adalah satu-satunya bagian aplikasi
 * ini yang bersaing langsung dengan buku tulis — dan buku tulis menang telak selama
 * aplikasinya tidak bisa menjawab "kapan saya bayar yang seratus ribu itu?". Itu sebabnya
 * riwayat setoran ditampilkan per kasbon, bukan cuma sisa utangnya.
 *
 * SELURUH perubahan uang lewat App\Actions\Kasbon\CatatPelunasanAction. Layar ini tidak
 * pernah menyentuh `jumlah_dibayar` sendiri: angka itu turunan dari riwayat setoran, dan
 * turunan yang bisa dinaikkan dari mana saja cepat atau lambat tidak lagi sama dengan
 * riwayatnya.
 *
 * Yang SENGAJA TIDAK ada di layar ini:
 *
 * - MENGUBAH JUMLAH UTANG kasbon yang lahir dari struk kasir. Angkanya milik transaksi itu;
 *   mengubahnya di sini membuat struk dan buku kasbon bercerita berbeda tentang belanja yang
 *   sama, dan tidak ada yang bisa memutuskan mana yang benar.
 * - MENGHAPUS kasbon. Yang keliru dibatalkan setorannya, atau utangnya dilunasi. Piutang
 *   yang bisa dihapus adalah piutang yang bisa dihilangkan oleh orang yang menerima uangnya.
 * - TAGIH LEWAT WHATSAPP sekali tekan. Ada di RENCANA sebagai pekerjaan sendiri: ia menyusun
 *   teks tagihan beserta rinciannya, bukan sekadar membuka percakapan.
 */
#[Layout('layouts.aplikasi')]
class Kasbon extends Component
{
    use MengirimToast, TerikatTenant, WithPagination;

    #[Url(as: 'cari')]
    public string $cari = '';

    /** 'belum' | 'lunas' | 'semua'. Bawaannya 'belum': yang dibawa orang ke sini adalah menagih. */
    #[Url(as: 'status')]
    public string $saringStatus = 'belum';

    /* ── Panel setoran ───────────────────────────────────────────────────── */

    public bool $panel = false;

    /**
     * Kasbon yang sedang disetor — #[Locked] karena ia penentu TUJUAN uangnya.
     *
     * Tanpa ini, muatan Livewire bisa menukar id ke kasbon LAIN MILIK TENANT YANG SAMA: uang
     * yang diserahkan Budi tercatat mengurangi utang Siti. Kedua barisnya memang ada dan
     * memang milik warung itu, jadi tidak ada satu pun pemeriksaan yang gagal.
     */
    #[Locked]
    public ?string $kasbonId = null;

    /**
     * Nominal setoran sebagai TEKS apa adanya yang diketik orang.
     *
     * Bukan `?float`: `(float) '150.000'` bernilai 150.0, jadi setoran Rp 150.000 tercatat
     * Rp 150 dan pelanggan tetap tertagih hampir seluruhnya. Cacat yang sama sudah pernah
     * terjadi di kolom harga beli nota belanja (komit 96d4844). Teksnya dibiarkan utuh sampai
     * App\Support\Uang membacanya.
     */
    public string $jumlahSetor = '';

    public string $tanggalSetor = '';

    public string $catatanSetor = '';

    /* ── Panel kasbon baru ───────────────────────────────────────────────── */

    public bool $panelBaru = false;

    public string $pelangganId = '';

    public string $jumlahUtang = '';

    public string $jatuhTempo = '';

    public string $catatanUtang = '';

    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'saringStatus'], true)) {
            $this->resetPage();
        }
    }

    /* ── Setoran ─────────────────────────────────────────────────────────── */

    public function setor(string $id): void
    {
        $kasbon = $this->kasbonMilikSaya($id);

        $this->kasbonId = $kasbon->getKey();
        $this->jumlahSetor = '';
        // Bawaannya HARI INI, bukan kosong: setoran hampir selalu dicatat pada hari uangnya
        // diterima, dan kotak tanggal kosong yang wajib diisi adalah rintangan di tengah
        // antrean untuk jawaban yang sudah bisa ditebak aplikasinya.
        $this->tanggalSetor = now()->toDateString();
        $this->catatanSetor = '';
        $this->panel = true;
        $this->resetValidation();
    }

    /** Mengisi kotak setoran dengan seluruh sisa utang — jalur yang paling sering dipakai. */
    public function setorPenuh(): void
    {
        $kasbon = $this->kasbonTerpilih();

        if ($kasbon === null) {
            return;
        }

        /*
         * DIBULATKAN KE BAWAH ke rupiah utuh, dan tiap katanya penting.
         *
         * Bulat, karena Uang::baca() menolak bentuk berdesimal — kolom decimal(15,2) bisa
         * menyimpan sisa 100000.50, dan mengisikannya apa adanya membuat jalur yang paling
         * sering dipakai orang justru yang paling sering ditolak, dengan pesan yang tidak
         * bisa dijelaskan siapa pun.
         *
         * KE BAWAH, bukan dibulatkan biasa, dan ini jalan buntu yang benar-benar terukur:
         * round(100000.50) = 100001, yang lalu DITOLAK aksinya sebagai "melebihi sisa utang".
         * Dua-duanya gagal, jadi kasbon bersen tidak akan pernah bisa dilunasi lewat tombol
         * ini. Ke bawah menyisakan Rp 0,50 — dan CatatPelunasanAction menyatakan sisa di
         * bawah satu rupiah sebagai lunas, karena tidak ada uang fisik sebesar itu.
         */
        $this->jumlahSetor = (string) (int) floor($kasbon->sisaUtang());
    }

    public function tutupPanel(): void
    {
        $this->panel = false;
        $this->resetValidation();
    }

    public function simpanSetoran(): void
    {
        $data = $this->validate([
            'jumlahSetor' => ['required', $this->aturanUang('Jumlah setoran')],
            /*
             * `before_or_equal:today` DI SINI, bukan hanya di aksinya.
             *
             * Aksinya memang menolak tanggal masa depan, tapi nilainya sudah dijepit ke
             * `now()` sebelum sampai ke sana (lihat di bawah) — jadi tanpa aturan ini,
             * tanggal bulan depan akan diam-diam tercatat sebagai HARI INI. Perbaikan senyap
             * atas angka yang salah ketik lebih buruk daripada penolakan: pemiliknya tidak
             * pernah tahu tanggal yang ia maksud tidak jadi dipakai.
             */
            'tanggalSetor' => ['required', 'date', 'before_or_equal:today'],
            'catatanSetor' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'jumlahSetor' => 'jumlah setoran',
            'tanggalSetor' => 'tanggal setor',
        ]);

        $kasbon = $this->kasbonTerpilih();

        if ($kasbon === null) {
            $this->toast('Kasbonnya tidak ditemukan lagi. Muat ulang halamannya.', 'galat');

            return;
        }

        try {
            /*
             * Aksinya melempar RuntimeException untuk penolakan yang MASUK AKAL bagi pemilik
             * (setoran melebihi sisa, kasbon sudah lunas, tanggal di masa depan). Ditangkap
             * dan diubah jadi toast, bukan dibiarkan jadi halaman galat: yang di depan layar
             * adalah orang yang sedang memegang uang pelanggan, dan halaman 500 di situ
             * membuatnya tidak tahu apakah uangnya sudah tercatat atau belum.
             */
            app(CatatPelunasanAction::class)->execute(
                $kasbon,
                (float) Uang::baca($data['jumlahSetor']),
                auth()->user(),
                /*
                 * Dijepit ke `now()` supaya setoran hari ini bertanda waktu jam sebenarnya,
                 * bukan 23:59 — rekap "masuk hari ini" membacanya, dan jam yang mengada-ada
                 * membuat urutan setoran dalam satu hari jadi acak. Untuk tanggal yang sudah
                 * lewat, akhir harinya yang dipakai; tanggal masa depan tidak pernah sampai
                 * ke sini karena sudah ditolak validasi di atas.
                 */
                Carbon::parse($data['tanggalSetor'])->endOfDay()->min(now()),
                catatan: $data['catatanSetor'] !== '' ? $data['catatanSetor'] : null,
            );
        } catch (RuntimeException $e) {
            $this->toast($e->getMessage(), 'galat');

            return;
        }

        $this->panel = false;
        $this->toast('Setoran tercatat. Sisa utang '.$kasbon->refresh()->customer?->nama.' diperbarui.');
    }

    /**
     * Membatalkan satu setoran yang telanjur salah dicatat.
     *
     * Dialog SweetAlert di Blade BUKAN pengamannya — muatan Livewire bisa dikirim tanpa pernah
     * melewatinya. Yang menahan di sini adalah TenantScope lewat findOrFail: setoran warung
     * lain menjawab 404, bukan terbatalkan diam-diam.
     */
    public function batalkanSetoran(string $id): void
    {
        $setoran = CreditPayment::findOrFail($id);

        app(CatatPelunasanAction::class)->batalkan($setoran);

        $this->toast('Setoran dibatalkan. Sisa utangnya kembali seperti sebelumnya, dan catatan '
            .'yang keliru tetap terbaca di riwayat.');
    }

    /* ── Kasbon baru (manual) ────────────────────────────────────────────── */

    public function tambahKasbon(): void
    {
        $this->reset(['pelangganId', 'jumlahUtang', 'jatuhTempo', 'catatanUtang']);
        $this->panelBaru = true;
        $this->resetValidation();
    }

    public function tutupPanelBaru(): void
    {
        $this->panelBaru = false;
        $this->resetValidation();
    }

    public function simpanKasbon(): void
    {
        $data = $this->validate([
            // `exists` DIBATASI tenant: tanpa itu, id pelanggan warung lain lolos dan
            // utangnya tercatat atas nama orang yang tidak pernah muncul di daftar ini.
            'pelangganId' => ['required', 'string', $this->aturanPelanggan()],
            'jumlahUtang' => ['required', $this->aturanUang('Jumlah utang')],
            'jatuhTempo' => ['nullable', 'date', 'after_or_equal:today'],
            'catatanUtang' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'pelangganId' => 'pelanggan',
            'jumlahUtang' => 'jumlah utang',
            'jatuhTempo' => 'jatuh tempo',
        ]);

        $nominal = (float) Uang::baca($data['jumlahUtang']);

        if ($nominal <= 0) {
            $this->addError('jumlahUtang', 'Jumlah utangnya harus lebih dari nol.');

            return;
        }

        CreditLedger::create([
            /*
             * `outlet_id` diambil dari cakupan user, bukan dari muatan klien. Untuk pemilik
             * yang tidak terikat satu cabang nilainya null, dan itu sengaja dibiarkan:
             * kolomnya nullable, dan menebak cabang untuk kasbon yang dicatat dari kantor
             * lebih buruk daripada mengosongkannya.
             */
            'outlet_id' => auth()->user()->scopedOutletId(),
            'customer_id' => $data['pelangganId'],
            'jumlah_utang' => $nominal,
            'tanggal_jatuh_tempo' => ($data['jatuhTempo'] ?? '') !== '' ? $data['jatuhTempo'] : null,
            'catatan' => ($data['catatanUtang'] ?? '') !== '' ? $data['catatanUtang'] : null,
        ]);

        $this->panelBaru = false;
        $this->toast('Kasbon dicatat.');
    }

    /* ── Penolong ────────────────────────────────────────────────────────── */

    /**
     * Nominal dibaca App\Support\Uang, bukan aturan `numeric`.
     *
     * `numeric|min:0` MELOLOSKAN "150.000" — `is_numeric()` menyatakannya sah dan `(float)`
     * membacanya 150. Di layar ini akibatnya langsung: setoran Rp 150.000 tercatat Rp 150,
     * dan pelanggan yang sudah membayar tetap tertagih hampir seluruhnya.
     *
     * @return Closure(string, mixed, Closure): void
     */
    private function aturanUang(string $label): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal) use ($label): void {
            if ($nilai === null || $nilai === '') {
                return;
            }

            if (! Uang::sah($nilai)) {
                $gagal($label.' tidak terbaca. Tulis angkanya saja, mis. 150.000 — tanpa sen dan tanpa huruf.');
            }
        };
    }

    /** @return Closure(string, mixed, Closure): void */
    private function aturanPelanggan(): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal): void {
            if (! is_string($nilai) || ! Customer::whereKey($nilai)->exists()) {
                $gagal('Pelanggannya tidak ditemukan. Pilih dari daftar.');
            }
        };
    }

    /** Kasbon yang barisnya benar-benar milik tenant ini; 404 kalau bukan. */
    private function kasbonMilikSaya(string $id): CreditLedger
    {
        return CreditLedger::with('customer')->findOrFail($id);
    }

    private function kasbonTerpilih(): ?CreditLedger
    {
        return $this->kasbonId !== null
            ? CreditLedger::with('customer')->find($this->kasbonId)
            : null;
    }

    /** @return Builder<CreditLedger> */
    private function kueriKasbon(): Builder
    {
        return CreditLedger::query()
            ->with(['customer', 'payments' => fn ($q) => $q->with('penerima')->latest('dibayar_pada')])
            ->when($this->saringStatus === 'belum', fn ($q) => $q->where('status', CreditStatus::BelumLunas->value))
            ->when($this->saringStatus === 'lunas', fn ($q) => $q->where('status', CreditStatus::Lunas->value))
            ->when($this->cari !== '', fn ($q) => $q->whereHas(
                'customer',
                fn ($c) => $c->where('nama', 'like', '%'.$this->cari.'%')
                    ->orWhere('no_hp', 'like', '%'.$this->cari.'%'),
            ));
    }

    public function render()
    {
        $terpilih = $this->kasbonTerpilih();

        return view('livewire.pages.owner.kasbon.kasbon', [
            'daftar' => $this->kueriKasbon()
                /*
                 * Yang paling lama menggantung muncul lebih dulu, bukan yang terbaru.
                 * Daftar penagihan diurutkan menurut siapa yang paling perlu ditagih, dan
                 * utang yang umurnya tiga bulan itulah yang paling mungkin tidak kembali.
                 */
                ->orderBy('created_at')
                ->paginate(config('nampan.per_halaman')),
            'kasbonTerpilih' => $terpilih,
            'sisaTerpilih' => $terpilih?->sisaUtang() ?? 0.0,
            'pelangganTersedia' => Customer::query()->orderBy('nama')->get(['id', 'nama', 'no_hp']),
            /*
             * Total piutang dihitung dari sumber yang SAMA dengan kolom per barisnya, dan
             * SENGAJA tidak ikut saringan: pemilik yang sedang melihat daftar "lunas" tetap
             * perlu tahu berapa yang masih menggantung. Angka yang berubah mengikuti saringan
             * terbaca sebagai piutang yang berkurang karena daftarnya disaring.
             */
            'totalPiutang' => (float) CreditLedger::query()
                ->where('status', CreditStatus::BelumLunas->value)
                ->sum(DB::raw('jumlah_utang - jumlah_dibayar')),
            'jumlahBerutang' => CreditLedger::query()
                ->where('status', CreditStatus::BelumLunas->value)
                ->distinct()
                ->count('customer_id'),
        ]);
    }
}
