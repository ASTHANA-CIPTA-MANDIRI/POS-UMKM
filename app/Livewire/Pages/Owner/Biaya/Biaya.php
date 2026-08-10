<?php

namespace App\Livewire\Pages\Owner\Biaya;

use App\Actions\Biaya\HitungBiayaHarianAction;
use App\Enums\PeriodeBiaya;
use App\Livewire\Concerns\MengirimToast;
use App\Livewire\Concerns\TerikatTenant;
use App\Models\Biaya\BiayaOperasional;
use App\Models\Tenant\Outlet;
use App\Support\Uang;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Biaya operasional: sewa, listrik, gaji, gas — beban warung yang berulang.
 *
 * KENAPA LAYAR INI ADA, dan kenapa ia bukan bagian dari kas keluar. Margin yang ditampilkan
 * layar Produk sekarang adalah margin KOTOR: "Ayam Goreng untung Rp 1.960" belum dipotong
 * sewa, listrik, dan gas. Pemilik yang membaca angka itu menyimpulkan warungnya untung
 * padahal bisa saja rugi setiap hari. Angka di layar inilah yang menutup selisih itu.
 *
 * ANGKA PERENCANAAN, BUKAN TRANSAKSI. Sewa Rp 1.500.000/bulan tetap membebani hari ini
 * meskipun sewanya baru dibayar tanggal 5, karena warungnya memang sedang memakai tempat itu
 * hari ini. Layar ini TIDAK PERNAH membuat baris kas — kalau ia melakukannya, uang yang sama
 * tercatat dua kali dan laporan kas jadi salah.
 *
 * Yang SENGAJA TIDAK ada:
 *  - MENANDAI "sudah dibayar". Itu pertanyaan kas, dan jawabannya ada di layar kas. Menaruh
 *    saklar bayar di sini membuat orang mengira pembayarannya tercatat sebagai uang keluar.
 *  - MEMBAGI biaya bersama ke tiap cabang secara rata. Pembagiannya butuh dasar (omzet?
 *    jumlah karyawan?) yang belum diputuskan pemilik, dan pembagi yang salah membuat satu
 *    cabang terlihat rugi karena menanggung beban cabang lain.
 */
#[Layout('layouts.aplikasi')]
class Biaya extends Component
{
    use MengirimToast, TerikatTenant, WithPagination;

    #[Url(as: 'cari')]
    public string $cari = '';

    /** Bawaannya hanya yang MASIH berjalan — yang sudah berhenti bukan beban hari ini. */
    #[Url(as: 'berhenti')]
    public bool $tampilkanBerhenti = false;

    /* ── Formulir ────────────────────────────────────────────────────────── */

    public bool $panel = false;

    /**
     * Baris yang sedang diubah — #[Locked] karena ia penentu TUJUAN penyimpanan.
     *
     * Sama seperti layar lain: tanpa ini, muatan Livewire bisa menukar id ke baris lain milik
     * tenant yang sama, dan formulir "Listrik" menimpa baris "Sewa" tanpa satu pun galat.
     */
    #[Locked]
    public ?string $biayaId = null;

    public string $nama = '';

    /**
     * Nominal sebagai TEKS apa adanya yang diketik orang.
     *
     * Bukan `?float`: `(float) '1.500.000'` bernilai 1.5, jadi sewa satu setengah juta
     * tercatat Rp 2 dan seluruh hitungan beban warung jadi tidak berarti. Teksnya dibiarkan
     * utuh sampai App\Support\Uang membacanya.
     */
    public string $nominal = '';

    public string $periode = 'bulanan';

    public string $outletId = '';

    public string $mulai = '';

    public string $selesai = '';

    public string $catatan = '';

    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'tampilkanBerhenti'], true)) {
            $this->resetPage();
        }
    }

    public function tambah(): void
    {
        $this->reset(['biayaId', 'nama', 'nominal', 'outletId', 'selesai', 'catatan']);
        $this->periode = 'bulanan';
        // Bawaannya HARI INI: biaya yang baru dicatat hampir selalu sudah berjalan, dan kotak
        // tanggal kosong yang wajib diisi adalah rintangan untuk jawaban yang sudah bisa
        // ditebak aplikasinya.
        $this->mulai = now()->toDateString();
        $this->panel = true;
        $this->resetValidation();
    }

    public function ubah(string $id): void
    {
        $biaya = $this->biayaMilikSaya($id);

        $this->biayaId = $biaya->getKey();
        $this->nama = $biaya->nama;
        // Rupiah yang diketik orang tidak punya sen, jadi yang ditampilkan kembali juga tidak:
        // kolom decimal(15,2) mengembalikan "1500000.00", dan bentuk itu DITOLAK Uang::baca()
        // kalau disimpan ulang tanpa disentuh — penolakan yang tidak bisa dijelaskan siapa pun.
        $this->nominal = (string) (int) round((float) $biaya->nominal);
        $this->periode = $biaya->periode->value;
        $this->outletId = (string) $biaya->outlet_id;
        // Kotak <input type="date"> hanya menerima Y-m-d; format tampilan Indonesia membuat
        // kotaknya terbuka KOSONG, dan menyimpan dari keadaan itu menghapus tanggalnya.
        $this->mulai = $biaya->mulai->format('Y-m-d');
        $this->selesai = $biaya->selesai?->format('Y-m-d') ?? '';
        $this->catatan = (string) $biaya->catatan;
        $this->panel = true;
        $this->resetValidation();
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
            'nominal' => ['required', $this->aturanUang()],
            'periode' => ['required', Rule::enum(PeriodeBiaya::class)],
            'outletId' => ['nullable', 'string', $this->aturanOutlet()],
            'mulai' => ['required', 'date'],
            /*
             * `after_or_equal:mulai`, dan ini bukan kerapian. Rentang terbalik membuat
             * berlakuPada() menjawab false untuk SETIAP tanggal — biayanya tersimpan, muncul
             * di daftar, dan tidak pernah ikut dihitung sama sekali. Pemilik melihat sewanya
             * ada di daftar dan beban hariannya tidak berubah, tanpa satu pun petunjuk kenapa.
             */
            'selesai' => ['nullable', 'date', 'after_or_equal:mulai'],
            'catatan' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'nama' => 'nama biaya',
            'nominal' => 'nominal',
            'periode' => 'periode',
            'outletId' => 'cabang',
            'mulai' => 'tanggal mulai',
            'selesai' => 'tanggal berhenti',
        ], messages: [
            'selesai.after_or_equal' => 'Tanggal berhentinya sebelum tanggal mulai. Kalau begitu biayanya tidak akan pernah ikut dihitung.',
        ]);

        $nominal = (float) Uang::baca($data['nominal']);

        if ($nominal <= 0) {
            $this->addError('nominal', 'Nominalnya harus lebih dari nol.');

            return;
        }

        $muatan = [
            'nama' => $data['nama'],
            'nominal' => $nominal,
            'periode' => $data['periode'],
            'outlet_id' => ($data['outletId'] ?? '') !== '' ? $data['outletId'] : null,
            'mulai' => $data['mulai'],
            'selesai' => ($data['selesai'] ?? '') !== '' ? $data['selesai'] : null,
            'catatan' => ($data['catatan'] ?? '') !== '' ? $data['catatan'] : null,
        ];

        if ($this->biayaId !== null) {
            $this->biayaMilikSaya($this->biayaId)->update($muatan);
            $this->toast('Biaya "'.$data['nama'].'" diperbarui.');
        } else {
            BiayaOperasional::create($muatan);
            $this->toast('Biaya "'.$data['nama'].'" dicatat.');
        }

        $this->panel = false;
    }

    /**
     * Menghentikan biaya per hari ini — jalur yang benar untuk biaya yang sudah tidak ada.
     *
     * BUKAN menghapus, dan bedanya penting: menghapus membuat hitungan bulan LALU ikut
     * berubah, seolah sewanya tidak pernah ada. Menghentikan membiarkan riwayatnya utuh dan
     * hanya melepaskan beban mulai hari ini.
     */
    public function hentikan(string $id): void
    {
        $biaya = $this->biayaMilikSaya($id);

        if ($biaya->selesai !== null) {
            $this->toast('Biaya ini sudah berhenti sejak '.$biaya->selesai->locale('id')->translatedFormat('j M Y').'.', 'info');

            return;
        }

        $biaya->update(['selesai' => now()->toDateString()]);

        $this->toast('"'.$biaya->nama.'" berhenti membebani mulai besok. Hitungan bulan-bulan sebelumnya tidak berubah.');
    }

    /**
     * Menghapus — hanya untuk yang salah catat.
     *
     * Dialog SweetAlert di Blade BUKAN pengamannya; ujinya memanggil metode ini langsung.
     * Yang menahan di sini TenantScope lewat findOrFail: baris warung lain menjawab 404.
     */
    public function hapus(string $id): void
    {
        $biaya = $this->biayaMilikSaya($id);
        $nama = $biaya->nama;

        $biaya->delete();

        $this->toast('Biaya "'.$nama.'" dihapus. Kalau biayanya memang pernah ada, lebih baik dihentikan daripada dihapus.');
    }

    /* ── Penolong ────────────────────────────────────────────────────────── */

    /**
     * Nominal dibaca App\Support\Uang, bukan aturan `numeric`.
     *
     * `numeric|min:0` MELOLOSKAN "1.500.000" — `is_numeric()` menyatakannya sah dan `(float)`
     * membacanya 1.5. Sewa satu setengah juta tercatat Rp 2, dan seluruh beban harian warung
     * jadi angka yang tidak berarti apa-apa.
     *
     * @return Closure(string, mixed, Closure): void
     */
    private function aturanUang(): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal): void {
            if ($nilai === null || $nilai === '') {
                return;
            }

            if (! Uang::sah($nilai)) {
                $gagal('Nominalnya tidak terbaca. Tulis angkanya saja, mis. 1.500.000 — tanpa sen dan tanpa huruf.');
            }
        };
    }

    /** @return Closure(string, mixed, Closure): void */
    private function aturanOutlet(): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal): void {
            if (! is_string($nilai) || $nilai === '') {
                return;
            }

            // Outlet::query() sudah tersaring TenantScope, jadi cabang warung lain dinyatakan
            // tidak ada — biaya tidak bisa ditempelkan ke cabang tetangga lewat muatan Livewire.
            if (! Outlet::query()->whereKey($nilai)->exists()) {
                $gagal('Cabangnya tidak ditemukan. Pilih dari daftar.');
            }
        };
    }

    private function biayaMilikSaya(string $id): BiayaOperasional
    {
        return BiayaOperasional::findOrFail($id);
    }

    /** @return Builder<BiayaOperasional> */
    private function kueriBiaya(): Builder
    {
        return BiayaOperasional::query()
            ->with('outlet:id,outlet_name')
            ->when($this->cari !== '', fn ($q) => $q->where('nama', 'like', '%'.$this->cari.'%'))
            ->unless($this->tampilkanBerhenti, fn ($q) => $q->berlaku());
    }

    public function render()
    {
        // Beban dihitung untuk cakupan user: pemilik melihat seluruh warung, manajer outlet
        // melihat cabangnya sendiri berikut biaya bersama.
        $ringkas = app(HitungBiayaHarianAction::class)->untuk(auth()->user()->scopedOutletId());

        return view('livewire.pages.owner.biaya.biaya', [
            'daftar' => $this->kueriBiaya()
                ->orderByDesc('nominal')
                ->orderBy('nama')
                ->paginate(config('nampan.per_halaman')),
            'perHari' => $ringkas['perHari'],
            'perBulan' => $ringkas['perBulan'],
            'jumlahBerjalan' => BiayaOperasional::query()->berlaku()->count(),
            'periodeTersedia' => PeriodeBiaya::cases(),
            'outletTersedia' => Outlet::query()->orderBy('outlet_name')->get(['id', 'outlet_name']),
        ]);
    }
}
