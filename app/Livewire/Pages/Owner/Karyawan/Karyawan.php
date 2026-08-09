<?php

namespace App\Livewire\Pages\Owner\Karyawan;

use App\Enums\CashSessionStatus;
use App\Enums\UserRole;
use App\Livewire\Concerns\MengirimToast;
use App\Livewire\Concerns\TerikatTenant;
use App\Models\Kas\CashSession;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Kelola karyawan: daftar, tambah, ubah, nonaktifkan, hapus.
 *
 * BAHAYA UTAMA LAYAR INI, dan ia berbeda dari layar lain mana pun di aplikasi ini:
 * `User` SENGAJA TIDAK memakai trait BelongsToTenant. Alasannya sah dan tertulis di
 * modelnya — global scope tenant akan ikut membatasi kueri auth guard dan membuat Super
 * Admin tidak bisa mengelola user lintas tenant. Akibatnya di sini: TIDAK ADA jaring
 * pengaman. Satu kueri yang lupa `where('tenant_id', ...)` akan menampilkan, menyunting,
 * atau menghapus karyawan warung lain, dan tidak ada satu pun lapisan di bawahnya yang
 * akan menahannya.
 *
 * Karena itu seluruh akses lewat kueriKaryawan() dan karyawanMilikSaya(). Jangan pernah
 * memanggil User::find() langsung di berkas ini.
 *
 * TIGA GERBANG yang masing-masing mencegah pemilik mengunci dirinya sendiri atau merusak
 * catatan uang, dan ketiganya ada di SERVER — bukan sekadar tombol yang disembunyikan:
 *
 *  1. Peran yang boleh diberikan DIBATASI. Owner tidak bisa membuat super_admin: itu peran
 *     pengelola platform, dan satu akun seperti itu melihat SELURUH merchant.
 *  2. Pemilik tidak bisa menonaktifkan, menurunkan peran, atau menghapus DIRINYA SENDIRI —
 *     dan tidak bisa menghapus owner terakhir. Warung tanpa satu pun owner aktif adalah
 *     warung yang tidak bisa dibuka lagi tanpa bantuan dari luar.
 *  3. Kasir yang sesi kasnya MASIH TERBUKA tidak bisa dihapus atau dinonaktifkan. Sesi yang
 *     menggantung tidak bisa ditutup siapa pun, jadi uang laci hari itu tidak pernah
 *     dicocokkan — dan selisihnya tidak akan pernah ketahuan.
 *
 * Yang SENGAJA TIDAK ada:
 *  - MELIHAT PIN yang sudah tersimpan. Kolomnya hash satu arah. Yang lupa PIN diberi PIN
 *    baru, dan itu memang satu-satunya jalan yang benar.
 *  - MENGIKAT PERANGKAT (device_id_terikat). Ada layar Outlet & perangkat tersendiri di
 *    RENCANA; menaruhnya di sini berarti dua layar mengubah kolom yang sama.
 */
#[Layout('layouts.aplikasi')]
class Karyawan extends Component
{
    use MengirimToast, TerikatTenant, WithPagination;

    /**
     * Peran yang boleh DIBERIKAN pemilik lewat layar ini.
     *
     * `super_admin` sengaja tidak ada: itu peran pengelola platform, dan satu akun seperti
     * itu melihat seluruh merchant, bukan cuma warung ini. Daftarnya dipakai DUA kali — untuk
     * mengisi pilihan di layar DAN untuk memvalidasi di server — supaya keduanya tidak pernah
     * berbeda pendapat. Pilihan yang cuma disembunyikan di layar tetap bisa dikirim lewat
     * muatan Livewire.
     *
     * @var array<int, string>
     */
    private const PERAN_BOLEH = ['manager_outlet', 'kasir', 'dapur', 'regional_manager', 'owner'];

    /** Panjang PIN kasir. Enam digit: cukup sulit ditebak, masih bisa dihafal. */
    private const PANJANG_PIN = 6;

    #[Url(as: 'cari')]
    public string $cari = '';

    #[Url(as: 'peran')]
    public string $saringPeran = '';

    /* ── Formulir ────────────────────────────────────────────────────────── */

    public bool $panel = false;

    /**
     * Karyawan yang sedang diubah — #[Locked] karena ia penentu TUJUAN penyimpanan.
     *
     * Di layar ini taruhannya lebih tinggi daripada layar lain: yang bisa tertukar adalah
     * PERAN dan PIN seseorang. Formulir "Kasir Andi" yang diam-diam menimpa baris pemilik
     * memberi PIN kasir kepada akun yang bisa membuka seluruh laporan keuangan.
     */
    #[Locked]
    public ?string $karyawanId = null;

    public string $nama = '';

    public string $peran = 'kasir';

    public string $outletId = '';

    public string $username = '';

    public string $email = '';

    /** PIN/kata sandi baru. Selalu kosong saat formulir dibuka — hash tidak bisa dibaca balik. */
    public string $rahasiaBaru = '';

    public bool $aktif = true;

    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'saringPeran'], true)) {
            $this->resetPage();
        }
    }

    public function tambah(): void
    {
        $this->reset(['karyawanId', 'nama', 'username', 'email', 'rahasiaBaru']);
        $this->peran = 'kasir';
        $this->outletId = '';
        $this->aktif = true;
        $this->panel = true;
        $this->resetValidation();
    }

    public function ubah(string $id): void
    {
        $karyawan = $this->karyawanMilikSaya($id);

        $this->karyawanId = $karyawan->getKey();
        $this->nama = $karyawan->name;
        $this->peran = $karyawan->role->value;
        $this->outletId = (string) $karyawan->outlet_id;
        $this->username = (string) $karyawan->username;
        $this->email = (string) $karyawan->email;
        // SELALU kosong: kolomnya hash satu arah, jadi tidak ada yang bisa ditampilkan.
        // Kotak yang terisi titik-titik palsu membuat orang mengira PIN lamanya ikut terkirim.
        $this->rahasiaBaru = '';
        $this->aktif = (bool) $karyawan->is_active;
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
        $sendiri = $this->karyawanId !== null && $this->karyawanId === auth()->id();
        $peran = UserRole::tryFrom($this->peran);
        $butuhOutlet = $peran?->requiresOutlet() ?? false;
        $baru = $this->karyawanId === null;

        /*
         * KEWAJIBAN memakai Rule::requiredIf, BUKAN aturan Closure.
         *
         * Ini cacat yang sudah benar-benar terjadi di berkas ini dan ditangkap uji: Laravel
         * TIDAK menjalankan aturan non-implisit untuk nilai yang kosong (null, '', array
         * kosong). Closure adalah aturan non-implisit, jadi gerbang "kasir wajib punya
         * cabang" yang ditulis sebagai Closure tidak pernah menyala justru pada keadaan yang
         * ingin ditahannya — kolomnya kosong. Yang terjadi kemudian: `outlet_id = ''` masuk
         * ke basis data dan ditolak kunci asing, jadi pemilik mendapat halaman galat basis
         * data alih-alih kalimat yang menjelaskan apa yang kurang.
         *
         * requiredIf implisit, jadi ia berjalan justru saat nilainya kosong.
         */
        $data = $this->validate([
            'nama' => ['required', 'string', 'max:255'],
            'peran' => ['required', Rule::in(self::PERAN_BOLEH), $this->aturanPeranSendiri($sendiri), $this->aturanAngkatOwner()],
            'outletId' => [Rule::requiredIf($butuhOutlet), 'nullable', 'string', $this->aturanOutlet()],
            /*
             * Username dan email unik SECARA GLOBAL, bukan per tenant.
             *
             * Bukan pilihan layar ini — kolomnya memang `->unique()` di migrasi, karena
             * keduanya dipakai auth guard yang berjalan SEBELUM tenant diketahui. Yang bisa
             * dilakukan di sini adalah mengatakannya dengan jujur, dan itu ada di pesan
             * galatnya: "sudah dipakai di aplikasi ini" — bukan "sudah dipakai di warung
             * Anda", yang akan membuat pemilik mencari-cari nama itu di daftarnya sendiri
             * dan tidak pernah menemukannya.
             */
            'username' => [
                Rule::requiredIf($baru && $butuhOutlet),
                'nullable', 'string', 'max:50', 'alpha_dash',
                Rule::unique('users', 'username')->whereNull('deleted_at')->ignore($this->karyawanId),
            ],
            'email' => [
                Rule::requiredIf($baru && ! $butuhOutlet),
                'nullable', 'email', 'max:255',
                Rule::unique('users', 'email')->whereNull('deleted_at')->ignore($this->karyawanId),
            ],
            'rahasiaBaru' => [Rule::requiredIf($baru), 'nullable', 'string', $this->aturanRahasia()],
            'aktif' => ['boolean', $this->aturanAktifSendiri($sendiri)],
        ], attributes: [
            'nama' => 'nama karyawan',
            'peran' => 'peran',
            'outletId' => 'cabang',
            'username' => 'username',
            'email' => 'email',
            'rahasiaBaru' => 'PIN',
        ], messages: [
            /*
             * Tiap pesan wajib menyebut AKIBATNYA, bukan cuma menyatakan medan itu kosong.
             * "Cabang wajib diisi" tidak memberi tahu bahwa akunnya akan gagal login — dan
             * gerbang login itu ada di layar lain, jauh dari sini, tanpa satu pun kalimat
             * yang menunjuk kembali ke layar ini.
             */
            'outletId.required' => 'Kasir, dapur, dan manajer outlet harus dikunci ke satu cabang — tanpa itu akunnya tidak bisa dipakai masuk.',
            'username.required' => 'Kasir dan dapur masuk pakai username + PIN, jadi usernamenya wajib diisi.',
            'email.required' => 'Pemilik dan manajer masuk pakai email, jadi emailnya wajib diisi.',
            'rahasiaBaru.required' => 'Karyawan baru wajib diberi PIN atau kata sandi, kalau tidak akunnya tidak bisa dipakai masuk.',
            'username.unique' => 'Username ":input" sudah dipakai di aplikasi ini. Pilih yang lain, mis. ditambah nama cabangnya.',
            'email.unique' => 'Email ":input" sudah dipakai di aplikasi ini.',
            'username.alpha_dash' => 'Username hanya boleh huruf, angka, tanda hubung, dan garis bawah — tanpa spasi.',
        ]);

        $peran = UserRole::from($data['peran']);

        $muatan = [
            'name' => $data['nama'],
            'role' => $peran,
            // Peran multi-cabang TIDAK dikunci ke satu outlet: kolomnya dikosongkan supaya
            // scopedOutletId() mengembalikan null dan pemilik melihat seluruh cabang.
            // `?: null` bukan hiasan — teks kosong yang lolos ke kolom kunci asing ditolak
            // basis data, dan pesannya bukan kalimat yang bisa dibaca pemilik warung.
            'outlet_id' => $peran->requiresOutlet() ? ($data['outletId'] ?: null) : null,
            'username' => ($data['username'] ?? '') !== '' ? $data['username'] : null,
            'email' => ($data['email'] ?? '') !== '' ? $data['email'] : null,
            'is_active' => $data['aktif'],
        ];

        if (($data['rahasiaBaru'] ?? '') !== '') {
            // PIN untuk yang login lewat username, kata sandi untuk yang login lewat email.
            // Keduanya di-hash oleh cast di model — tidak pernah disimpan apa adanya.
            $muatan[$peran->requiresOutlet() ? 'pin_hash' : 'password'] = $data['rahasiaBaru'];
        }

        if ($this->karyawanId !== null) {
            $this->karyawanMilikSaya($this->karyawanId)->update($muatan);
            $this->toast('Data '.$data['nama'].' diperbarui.');
        } else {
            /*
             * tenant_id diisi DI SINI, dan hanya di sini.
             *
             * User tidak memakai BelongsToTenant, jadi tidak ada yang mengisinya otomatis.
             * Karyawan yang lolos tanpa tenant tidak muncul di daftar mana pun — termasuk
             * daftar ini — tapi tetap bisa login, dan `scopedOutletId()`-nya null.
             */
            $karyawan = new User($muatan);
            $karyawan->tenant_id = auth()->user()->tenant_id;
            $karyawan->save();

            $this->toast('Karyawan '.$data['nama'].' ditambahkan.');
        }

        $this->panel = false;
    }

    /**
     * Menonaktifkan/mengaktifkan tanpa membuka formulir — jalur yang paling sering dipakai.
     *
     * Karyawan berhenti kerja jauh lebih sering daripada karyawan dihapus, dan menonaktifkan
     * adalah jawaban yang benar untuk itu: riwayat transaksinya tetap menunjuk orang yang ada.
     */
    public function saklarAktif(string $id): void
    {
        $karyawan = $this->karyawanMilikSaya($id);

        if ($karyawan->getKey() === auth()->id()) {
            $this->toast('Akun sendiri tidak bisa dinonaktifkan. Minta pemilik lain kalau memang perlu.', 'galat');

            return;
        }

        if ($karyawan->is_active && ($sesi = $this->sesiKasTerbuka($karyawan)) !== null) {
            $this->toast(
                $karyawan->name.' masih punya sesi kas terbuka sejak '
                .$sesi->dibuka_pada->locale('id')->translatedFormat('j M H:i')
                .'. Tutup kasirnya dulu — kalau tidak, uang laci hari itu tidak pernah dicocokkan.',
                'galat',
            );

            return;
        }

        $karyawan->update(['is_active' => ! $karyawan->is_active]);

        $this->toast($karyawan->name.($karyawan->is_active ? ' diaktifkan lagi.' : ' dinonaktifkan. Riwayatnya tetap tersimpan.'));
    }

    /**
     * Menghapus karyawan — dengan GERBANG SERVER, bukan hanya dialog.
     *
     * Dialog SweetAlert di Blade BUKAN pengamannya: muatan Livewire bisa dikirim tanpa pernah
     * melewatinya, dan ujinya memanggil metode ini langsung.
     */
    public function hapus(string $id): void
    {
        $karyawan = $this->karyawanMilikSaya($id);

        if ($karyawan->getKey() === auth()->id()) {
            $this->toast('Akun sendiri tidak bisa dihapus.', 'galat');

            return;
        }

        /*
         * Owner TERAKHIR tidak boleh hilang.
         *
         * Bukan kehati-hatian berlebihan: layar ini satu-satunya pintu mengelola karyawan,
         * dan pintunya sendiri hanya bisa dibuka peran back office. Warung tanpa satu pun
         * owner aktif adalah warung yang tidak bisa dibuka lagi tanpa bantuan dari luar —
         * dan "bantuan dari luar" berarti seseorang menyunting basis data.
         */
        if ($karyawan->role === UserRole::Owner && $this->jumlahOwnerAktif() <= 1) {
            $this->toast('Ini satu-satunya pemilik yang aktif. Angkat pemilik lain dulu sebelum menghapusnya.', 'galat');

            return;
        }

        if (($sesi = $this->sesiKasTerbuka($karyawan)) !== null) {
            $this->toast(
                $karyawan->name.' masih punya sesi kas terbuka sejak '
                .$sesi->dibuka_pada->locale('id')->translatedFormat('j M H:i')
                .'. Tutup kasirnya dulu, baru orangnya bisa dihapus.',
                'galat',
            );

            return;
        }

        $nama = $karyawan->name;

        /*
         * Username dan email DILEPAS saat dihapus.
         *
         * Keduanya unik global, dan aturan uniknya mengabaikan baris terhapus
         * (whereNull('deleted_at')). Tanpa pelepasan ini, "kasir1" tetap terpakai selamanya
         * oleh baris yang tidak muncul di layar mana pun — pemilik yang salah hapus lalu
         * membuatnya lagi akan ditolak dengan alasan yang tidak bisa ia lihat buktinya.
         */
        $karyawan->forceFill(['username' => null, 'email' => null])->save();
        $karyawan->delete();

        $this->toast('Karyawan '.$nama.' dihapus. Riwayat transaksinya tetap tersimpan.');
    }

    /* ── Penolong ────────────────────────────────────────────────────────── */

    /**
     * Cabang yang dipilih harus benar-benar ada DI WARUNG INI.
     *
     * Kewajibannya sendiri dipegang Rule::requiredIf di simpan() — aturan Closure tidak
     * pernah berjalan untuk nilai kosong, jadi menaruh kewajiban di sini berarti gerbangnya
     * diam justru saat kolomnya kosong. Yang tersisa di sini: memastikan id-nya sah.
     *
     * Outlet::query() sudah tersaring TenantScope, jadi id milik warung lain dinyatakan tidak
     * ada — kasir warung ini tidak bisa dikunci ke cabang tetangga lewat muatan Livewire.
     *
     * @return Closure(string, mixed, Closure): void
     */
    private function aturanOutlet(): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal): void {
            if (! is_string($nilai) || $nilai === '') {
                return;
            }

            if (! Outlet::query()->whereKey($nilai)->exists()) {
                $gagal('Cabangnya tidak ditemukan. Pilih dari daftar.');
            }
        };
    }

    /**
     * PIN enam digit untuk peran kasir; kata sandi minimal delapan untuk peran back office.
     *
     * Dibedakan karena jalur masuknya memang berbeda: kasir mengetik PIN di layar sentuh
     * sambil antre, pemilik mengetik kata sandi di papan ketik. Memaksa kasir mengetik kata
     * sandi delapan karakter di antrean membuat PIN-nya ditempel di dinding.
     */
    private function aturanRahasia(): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal): void {
            if ($nilai === null || $nilai === '') {
                // Kosong berarti "jangan diubah" saat menyunting. Saat MEMBUAT, kekosongannya
                // ditangani Rule::requiredIf di simpan() — aturan Closure seperti ini tidak
                // pernah dijalankan Laravel untuk nilai kosong, jadi kewajiban tidak boleh
                // ditaruh di sini.
                return;
            }

            $peran = UserRole::tryFrom($this->peran);

            if ($peran !== null && $peran->requiresOutlet()) {
                if (preg_match('/^\d{'.self::PANJANG_PIN.'}$/', (string) $nilai) !== 1) {
                    $gagal('PIN harus tepat '.self::PANJANG_PIN.' angka.');
                }

                return;
            }

            if (mb_strlen((string) $nilai) < 8) {
                $gagal('Kata sandi minimal 8 karakter.');
            }
        };
    }

    /**
     * HANYA pemilik yang boleh memberikan peran pemilik.
     *
     * DITEMUKAN LEWAT UJI MUTASI, dan bukan lewat mutasi yang gagal — lewat mutasi yang
     * HIJAU. Gerbang "owner terakhir tidak bisa dihapus" tidak pernah teruji karena ujinya
     * tertutup gerbang "tidak bisa menghapus diri sendiri", dan menelusuri kapan gerbang itu
     * SEBENARNYA bisa menyala memunculkan keadaan yang belum dipikirkan: manajer outlet dan
     * manajer regional juga boleh membuka layar ini (lihat grup rute back office). Jadi
     * merekalah yang bisa sampai ke keadaan "menghapus satu-satunya pemilik aktif".
     *
     * Kalau mereka bisa menghapus pemilik, mereka juga bisa MENGANGKAT DIRI jadi pemilik —
     * dan sesudah itu tidak ada lagi yang bisa menurunkannya. Peran pemilik membawa akses
     * lintas cabang dan perlindungan "owner terakhir" yang tidak dimiliki peran lain, jadi
     * memberikannya adalah keputusan yang hanya boleh diambil pemilik.
     *
     * Diperiksa terhadap peran ORANG YANG LOGIN, bukan terhadap layarnya: pilihan yang
     * disembunyikan di layar tetap bisa dikirim lewat muatan Livewire.
     *
     * @return Closure(string, mixed, Closure): void
     */
    private function aturanAngkatOwner(): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal): void {
            if ($nilai !== UserRole::Owner->value) {
                return;
            }

            if (auth()->user()->role !== UserRole::Owner) {
                $gagal('Hanya pemilik yang bisa mengangkat orang jadi pemilik.');
            }
        };
    }

    /** Pemilik tidak boleh menurunkan peran dirinya sendiri. */
    private function aturanPeranSendiri(bool $sendiri): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal) use ($sendiri): void {
            if (! $sendiri || $nilai === auth()->user()->role->value) {
                return;
            }

            // Menurunkan peran sendiri berarti kehilangan akses ke layar ini pada penyimpanan
            // yang sama — tidak ada langkah kedua untuk membatalkannya.
            $gagal('Peran akun sendiri tidak bisa diubah dari sini. Minta pemilik lain yang mengubahnya.');
        };
    }

    /** Pemilik tidak boleh menonaktifkan dirinya sendiri. */
    private function aturanAktifSendiri(bool $sendiri): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal) use ($sendiri): void {
            if ($sendiri && $nilai === false) {
                $gagal('Akun sendiri tidak bisa dinonaktifkan — nanti tidak bisa masuk lagi.');
            }
        };
    }

    private function sesiKasTerbuka(User $karyawan): ?CashSession
    {
        return CashSession::query()
            ->where('staff_id', $karyawan->getKey())
            ->where('status', CashSessionStatus::Terbuka->value)
            ->latest('dibuka_pada')
            ->first();
    }

    private function jumlahOwnerAktif(): int
    {
        return $this->kueriDasar()
            ->where('role', UserRole::Owner->value)
            ->where('is_active', true)
            ->count();
    }

    /**
     * Kueri dasar yang SUDAH tersaring tenant.
     *
     * SATU-SATUNYA titik masuk ke tabel users di berkas ini. User tidak punya global scope
     * tenant (lihat docblock kelas), jadi kueri yang tidak lewat sini tidak tersaring apa pun.
     *
     * @return Builder<User>
     */
    private function kueriDasar(): Builder
    {
        return User::query()->where('tenant_id', auth()->user()->tenant_id);
    }

    /** Karyawan yang barisnya benar-benar milik tenant ini; 404 kalau bukan. */
    private function karyawanMilikSaya(string $id): User
    {
        return $this->kueriDasar()->whereKey($id)->firstOrFail();
    }

    /** @return Builder<User> */
    private function kueriKaryawan(): Builder
    {
        return $this->kueriDasar()
            ->with('outlet')
            ->when($this->cari !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$this->cari.'%')
                ->orWhere('username', 'like', '%'.$this->cari.'%')
                ->orWhere('email', 'like', '%'.$this->cari.'%')))
            ->when(
                in_array($this->saringPeran, self::PERAN_BOLEH, true),
                fn ($q) => $q->where('role', $this->saringPeran),
            );
    }

    public function render()
    {
        return view('livewire.pages.owner.karyawan.karyawan', [
            'daftar' => $this->kueriKaryawan()
                // Yang aktif lebih dulu, lalu menurut peran, lalu nama. Daftar yang diawali
                // orang-orang yang sudah berhenti membuat pemilik menggulir tiap kali.
                ->orderByDesc('is_active')
                ->orderBy('role')
                ->orderBy('name')
                ->paginate(config('nampan.per_halaman')),
            'jumlahKaryawan' => $this->kueriDasar()->count(),
            'jumlahAktif' => $this->kueriDasar()->where('is_active', true)->count(),
            /*
             * Peran `owner` hanya ditawarkan kepada pemilik. Menyembunyikannya BUKAN
             * pengamannya (gerbangnya di aturanAngkatOwner), tapi menawarkan pilihan yang
             * pasti ditolak membuat manajer mengisi formulir sampai selesai lalu ditolak di
             * langkah terakhir — tanpa pernah tahu sebabnya sebelum itu.
             */
            'peranTersedia' => array_values(array_map(
                UserRole::from(...),
                array_filter(
                    self::PERAN_BOLEH,
                    fn (string $p) => $p !== UserRole::Owner->value
                        || auth()->user()->role === UserRole::Owner,
                ),
            )),
            'outletTersedia' => Outlet::query()->orderBy('outlet_name')->get(['id', 'outlet_name']),
            'panjangPin' => self::PANJANG_PIN,
        ]);
    }
}
