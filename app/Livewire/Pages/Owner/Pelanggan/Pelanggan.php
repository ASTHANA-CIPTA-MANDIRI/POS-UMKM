<?php

namespace App\Livewire\Pages\Owner\Pelanggan;

use App\Enums\CreditStatus;
use App\Livewire\Concerns\MengirimToast;
use App\Livewire\Concerns\TerikatTenant;
use App\Models\Pelanggan\Customer;
use App\Support\NomorHp;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Kelola pelanggan: daftar, tambah, ubah, hapus.
 *
 * KENAPA LAYAR INI LEBIH DULU DARIPADA KASBON, padahal urutan di RENCANA sebaliknya.
 * `credit_ledgers.customer_id` TIDAK nullable — tiap baris kasbon wajib menunjuk seseorang.
 * Tanpa pintu untuk membuat orangnya, layar Kasbon hanya bisa dibangun di atas pelanggan
 * hasil seeder, dan gerbang-gerbangnya tidak akan pernah teruji dengan data sungguhan.
 *
 * Yang SENGAJA TIDAK ada di layar ini:
 *
 * - POIN. Kolomnya ada di `customers` sejak migrasi pertama, tapi TIDAK ada satu baris kode
 *   pun di aplikasi ini yang menaikkan atau membacanya — tidak ada mesin loyalitas. Medan
 *   yang bisa diisi tapi tidak berakibat apa-apa lebih buruk daripada medan yang tidak ada:
 *   pemilik yang mengetik 100 poin di situ percaya pelanggannya punya 100 poin, dan tidak
 *   ada layar mana pun yang akan menyanggahnya. Kolomnya dibiarkan di nilai bawaannya, 0.
 * - SALDO KASBON sebagai medan yang bisa diketik. Angkanya HASIL dari baris-baris kasbon,
 *   bukan sesuatu yang disetel. Medan yang bisa diketik di sini akan membuat saldo di layar
 *   ini berbeda dengan jumlah barisnya sendiri, dan tidak ada cara memutuskan mana yang benar.
 * - PENGGABUNGAN dua pelanggan yang ternyata orang yang sama. Kebutuhan nyata (lihat gerbang
 *   nomor unik di bawah), tapi ia memindahkan utang dari satu orang ke orang lain — itu
 *   pekerjaan sendiri dengan jejak auditnya, bukan tempelan di formulir.
 */
#[Layout('layouts.aplikasi')]
class Pelanggan extends Component
{
    use MengirimToast, TerikatTenant, WithPagination;

    #[Url(as: 'cari')]
    public string $cari = '';

    /** Saringan "yang masih punya utang" — pintu tercepat dari daftar ini ke pekerjaan menagih. */
    #[Url(as: 'utang')]
    public bool $hanyaBerutang = false;

    /* ── Formulir ────────────────────────────────────────────────────────── */

    public bool $panel = false;

    /**
     * Baris mana yang sedang diubah — #[Locked] karena ia penentu TUJUAN penyimpanan.
     *
     * Alasan lengkapnya sama dengan Bahan::$bahanId: tanpa ini, muatan Livewire bisa menukar
     * id ke pelanggan LAIN MILIK TENANT YANG SAMA, dan formulir "Budi" menimpa baris "Siti"
     * tanpa satu pun galat — semua pemeriksaannya lolos, karena barisnya memang ada dan
     * memang miliknya. Di layar ini akibatnya menyentuh uang: baris kasbon menunjuk
     * `customer_id`, jadi nama yang tertimpa membuat utang Siti tercatat atas nama Budi.
     */
    #[Locked]
    public ?string $pelangganId = null;

    public string $nama = '';

    public string $noHp = '';

    public string $email = '';

    public string $tanggalLahir = '';

    public function updated(string $properti): void
    {
        // Mengubah saringan harus mengembalikan ke halaman pertama; kalau tidak, orang
        // melihat daftar kosong hanya karena masih berada di halaman 3.
        if (in_array($properti, ['cari', 'hanyaBerutang'], true)) {
            $this->resetPage();
        }
    }

    public function tambah(): void
    {
        $this->reset(['pelangganId', 'nama', 'noHp', 'email', 'tanggalLahir']);
        $this->panel = true;
        $this->resetValidation();
    }

    public function ubah(string $id): void
    {
        $pelanggan = Customer::findOrFail($id);

        $this->pelangganId = $pelanggan->getKey();
        $this->nama = $pelanggan->nama;
        $this->noHp = (string) $pelanggan->no_hp;
        $this->email = (string) $pelanggan->email;
        // Kolom `date` HTML hanya menerima Y-m-d. Format tampilan Indonesia (d/m/Y) membuat
        // kotaknya terbuka KOSONG untuk pelanggan yang tanggal lahirnya sudah terisi, dan
        // menyimpan dari keadaan itu MENGHAPUS tanggalnya.
        $this->tanggalLahir = $pelanggan->tanggal_lahir?->format('Y-m-d') ?? '';
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
            'noHp' => ['nullable', 'string', 'max:30', $this->aturanNomor(), $this->aturanNomorUnik()],
            'email' => ['nullable', 'email', 'max:255'],
            /*
             * `before:today`, bukan `date`. Tanggal lahir di masa depan bukan salah ketik yang
             * lucu — kolom ini akan dipakai fitur ucapan ulang tahun (ada di RENCANA), dan
             * tanggal 2087 berarti pelanggan itu tidak pernah masuk daftar ucapan sekali pun,
             * tanpa ada yang tahu kenapa.
             */
            'tanggalLahir' => ['nullable', 'date', 'before:today'],
        ], attributes: [
            'nama' => 'nama pelanggan',
            'noHp' => 'nomor HP',
            'email' => 'email',
            'tanggalLahir' => 'tanggal lahir',
        ]);

        $muatan = [
            'nama' => $data['nama'],
            // Dibakukan SEBELUM disimpan, bukan saat ditampilkan. Alasannya di App\Support\NomorHp:
            // bentuk yang berbeda-beda membuat aturan unik di bawah tidak pernah menemukan
            // kembarannya, dan utang satu orang terpecah ke beberapa baris.
            'no_hp' => NomorHp::bakukan($data['noHp'] ?? null),
            'email' => ($data['email'] ?? '') !== '' ? $data['email'] : null,
            'tanggal_lahir' => ($data['tanggalLahir'] ?? '') !== '' ? $data['tanggalLahir'] : null,
        ];

        if ($this->pelangganId !== null) {
            Customer::findOrFail($this->pelangganId)->update($muatan);
            $this->toast('Pelanggan "'.$data['nama'].'" diperbarui.');
        } else {
            Customer::create($muatan);
            $this->toast('Pelanggan "'.$data['nama'].'" ditambahkan.');
        }

        $this->panel = false;
    }

    /**
     * Bentuk nomornya masuk akal.
     *
     * Bukan `regex` di aturan validasi, melainkan penolong bersama, supaya layar Kasbon dan
     * impor pelanggan nanti memakai penilaian yang SAMA. Dua tempat yang menilai "nomor yang
     * sah" sendiri-sendiri cepat atau lambat berbeda pendapat, dan yang satu menyimpan apa
     * yang ditolak yang lain.
     *
     * @return Closure(string, mixed, Closure): void
     */
    private function aturanNomor(): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal): void {
            if ($nilai === null || $nilai === '') {
                return;
            }

            if (NomorHp::bakukan((string) $nilai) === null) {
                $gagal('Nomor HP tidak terbaca. Tulis angkanya saja, mis. 0812-3456-7890.');

                return;
            }

            if (! NomorHp::sah((string) $nilai)) {
                $gagal('Nomor HP kependekan atau kepanjangan. Periksa lagi angkanya — '
                    .'mis. 0812-3456-7890.');
            }
        };
    }

    /**
     * Satu nomor HP hanya boleh dipegang satu pelanggan.
     *
     * KENAPA GERBANGNYA DI NOMOR, BUKAN DI NAMA. Nama kembar itu biasa dan sah — ada tiga
     * "Budi" di buku kasbon mana pun, dan melarangnya memaksa pemilik mengarang "Budi 2".
     * Nomor kembar sebaliknya hampir selalu berarti orang yang SAMA dimasukkan dua kali, dan
     * akibatnya bukan sekadar daftar yang kotor: kasbon menempel pada `customer_id`, jadi
     * utang orang itu terpecah ke dua baris. Pemilik membuka salah satunya, melihat
     * Rp 50.000, dan menagih Rp 50.000 padahal yang benar Rp 150.000. Tidak ada galat di
     * layar mana pun — selisihnya muncul sebagai uang yang tidak pernah kembali.
     *
     * Dibandingkan dengan nomor yang SUDAH DIBAKUKAN, dan itu bagian yang membuat gerbangnya
     * bekerja sama sekali: "0812-3456-7890" dan "+62 812 3456 7890" adalah teks yang berbeda
     * bagi basis data, jadi Rule::unique atas teks mentah meloloskan keduanya.
     *
     * Yang terhapus DIABAIKAN (soft delete): pelanggan yang salah hapus harus bisa dimasukkan
     * lagi dengan nomornya sendiri. Konsekuensinya diketahui — barisnya jadi dua di basis data
     * — tapi yang lama tidak muncul di layar mana pun dan utangnya sudah nol (dijaga gerbang
     * hapus() di bawah).
     *
     * @return Closure(string, mixed, Closure): void
     */
    private function aturanNomorUnik(): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal): void {
            $baku = NomorHp::bakukan(is_string($nilai) ? $nilai : null);

            if ($baku === null) {
                return;
            }

            $kembar = Customer::query()
                ->where('no_hp', $baku)
                ->when($this->pelangganId !== null, fn ($q) => $q->whereKeyNot($this->pelangganId))
                ->first();

            if ($kembar !== null) {
                // Namanya disebut. "Nomor sudah dipakai" membuat pemilik mencari sendiri di
                // daftar 300 baris; menyebut namanya membuat ia langsung tahu ini orang yang
                // sama, dan berhenti membuat baris kedua.
                $gagal('Nomor ini sudah dipakai '.$kembar->nama.'. Kalau memang orang yang sama, '
                    .'ubah data yang itu saja — kasbonnya menempel di sana.');
            }
        };
    }

    /**
     * Menghapus pelanggan — dengan GERBANG SERVER, bukan hanya dialog.
     *
     * PELANGGAN YANG MASIH BERUTANG TIDAK BOLEH DIHAPUS, dan ini cacat sungguhan yang sedang
     * menunggu, bukan kehati-hatian yang berlebihan. Customer memakai SoftDeletes, jadi
     * menghapus hanya mengisi `deleted_at`; baris `credit_ledgers`-nya TIDAK ikut terhapus dan
     * TIDAK ikut lunas. Yang terjadi sesudahnya:
     *
     *   1. Baris kasbonnya tetap 'belum_lunas' di basis data.
     *   2. Dasbor tetap menjumlahkannya — Dasbor.php menjumlah CreditLedger berstatus
     *      belum_lunas TANPA menyaring `deleted_at` pelanggannya.
     *   3. Daftar ini dan layar Kasbon tidak lagi menampilkan orangnya (SoftDeletingScope).
     *
     * Hasilnya: dasbor menunjukkan piutang Rp 500.000 yang tidak bisa ditelusuri ke satu pun
     * baris yang terlihat di layar mana pun. Pemilik tidak punya cara menemukan siapa yang
     * berutang, dan tidak punya cara membuat angka itu hilang.
     *
     * Dialog SweetAlert di Blade BUKAN pengamannya. Muatan Livewire bisa dikirim tanpa pernah
     * melewati dialog apa pun, jadi gerbangnya di sini — dan ujinya memanggil metode ini
     * langsung, tanpa dialog.
     *
     * RIWAYAT TRANSAKSI TIDAK menahan, dan itu keputusan sadar: `transactions.customer_id`
     * memakai nullOnDelete di basis data dan tidak pernah tersentuh oleh soft delete, jadi
     * struk lama tetap utuh dengan pelanggan yang tidak lagi muncul di daftar. Menahannya
     * membuat setiap pelanggan yang pernah belanja sekali jadi tidak-bisa-dihapus selamanya,
     * dan itu hampir semua pelanggan — daftar yang tidak bisa dibersihkan berhenti dipakai.
     */
    public function hapus(string $id): void
    {
        $pelanggan = Customer::findOrFail($id);
        $nama = $pelanggan->nama;
        $utang = $pelanggan->totalUtang();

        if ($utang > 0) {
            $this->toast(
                $nama.' masih punya kasbon belum lunas '.$this->rupiah($utang)
                .'. Lunasi atau hapus dulu kasbonnya, baru pelanggannya bisa dihapus.',
                'galat',
            );

            return;
        }

        $pelanggan->delete();

        $this->toast('Pelanggan "'.$nama.'" dihapus. Struk dan kasbon lama tetap tersimpan.');
    }

    private function rupiah(float $nilai): string
    {
        return 'Rp '.number_format($nilai, 0, ',', '.');
    }

    /** @return Builder<Customer> */
    private function kueriPelanggan(): Builder
    {
        return Customer::query()
            /*
             * Sisa utang dijumlah di BASIS DATA, bukan lewat totalUtang() per baris.
             *
             * totalUtang() adalah satu kueri tambahan untuk setiap baris — sepuluh baris jadi
             * sebelas kueri, dan angkanya tetap sama. Yang lebih penting: saringan
             * "masih berutang" di bawah harus menyaring SEBELUM halaman dipotong. Menyaring
             * di PHP sesudah paginate() membuat halaman 1 berisi 3 baris dan halaman 2 berisi
             * 7 — daftar yang jumlah barisnya berubah-ubah terbaca sebagai data yang hilang.
             */
            ->withSum(
                ['creditLedgers as sisa_utang' => fn ($q) => $q->where('status', CreditStatus::BelumLunas->value)],
                DB::raw('jumlah_utang - jumlah_dibayar'),
            )
            ->when($this->cari !== '', function ($q) {
                $kata = '%'.$this->cari.'%';
                // Nomor ikut dicari lewat bentuk BAKUNYA: orang yang mengetik "0812-3456"
                // di kotak cari tidak menemukan apa pun kalau yang dibandingkan teks mentah,
                // karena yang tersimpan "08123456…" tanpa tanda hubung.
                $bakuCari = NomorHp::bakukan($this->cari);

                $q->where(fn ($w) => $w
                    ->where('nama', 'like', $kata)
                    ->orWhere('no_hp', 'like', $kata)
                    ->when($bakuCari !== null, fn ($n) => $n->orWhere('no_hp', 'like', '%'.$bakuCari.'%')));
            })
            ->when($this->hanyaBerutang, fn ($q) => $q->whereHas(
                'creditLedgers',
                fn ($c) => $c->where('status', CreditStatus::BelumLunas->value)
                    ->whereColumn('jumlah_dibayar', '<', 'jumlah_utang'),
            ));
    }

    public function render()
    {
        return view('livewire.pages.owner.pelanggan.pelanggan', [
            'daftar' => $this->kueriPelanggan()
                ->orderBy('nama')
                ->paginate(config('nampan.per_halaman')),
            'jumlahPelanggan' => Customer::count(),
            /*
             * Total piutang seluruh pelanggan, untuk kalimat pengantar. Dihitung dari sumber
             * yang SAMA dengan kolom per barisnya — dua rumus untuk satu angka cepat atau
             * lambat berbeda, dan yang berbeda di sini adalah jumlah uang yang belum kembali.
             */
            'totalPiutang' => (float) Customer::query()
                ->join('credit_ledgers', 'credit_ledgers.customer_id', '=', 'customers.id')
                ->whereNull('credit_ledgers.deleted_at')
                ->where('credit_ledgers.status', CreditStatus::BelumLunas->value)
                ->sum(DB::raw('credit_ledgers.jumlah_utang - credit_ledgers.jumlah_dibayar')),
        ]);
    }
}
