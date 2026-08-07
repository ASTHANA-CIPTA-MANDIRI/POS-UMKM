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
 *
 * KONSEKUENSI LANGSUNG dari keputusan itu, dan kenapa ada $outletTerkunci: kunci baris
 * adalah id barang, dan barang bersifat TENANT — bukan per outlet. Jadi angka yang dihitung
 * di Cabang A punya kunci yang sama persis di Cabang B, dan dropdown outlet yang diganti di
 * tengah penghitungan akan memindahkan seluruh hasil hitung ke cabang yang salah tanpa satu
 * pun galat. Lihat $outletTerkunci, updatedOutletId(), dan penolakan di simpan().
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
     * Baris yang kolom fisiknya SENGAJA dikosongkan pemilik, di-key kunci baris.
     *
     * Ada karena `unset($sistemSaatDibuka[...])` saja tidak bertahan: Livewire merender
     * sesudah setiap pembaruan, dan rekamAngkaTerbaca() akan mengisinya ulang selama
     * barisnya masih tampak di halaman. Penanda ini yang membuat pembersihannya berlaku
     * sampai barisnya diketik lagi. #[Locked] karena ia menentukan isi catatan audit.
     *
     * @var array<string, bool>
     */
    #[Locked]
    public array $dibersihkan = [];

    /**
     * Outlet tempat angka di lembar ini DIHITUNG — bukan outlet yang sedang dipilih.
     *
     * Kuncinya ada karena kunci baris ($fisik) adalah `product_id`/`raw_material_id`, dan
     * produk bersifat TENANT: barang yang sama punya kunci yang sama di semua cabang. Jadi
     * angka yang dihitung di Cabang A "cocok" di Cabang B, dan tanpa kunci ini angka itu
     * akan disimpan ke cabang mana pun yang sedang terpilih saat tombol simpan ditekan.
     *
     * CACAT YANG DITUTUP (terbukti): Beras A=100, B=20. Ketik 50 di A, ganti dropdown ke B,
     * Simpan ⇒ B menjadi 50, mutasi +30 tercatat di cabang yang salah, dan A tidak
     * tersentuh. Yang membuatnya jauh lebih berbahaya: catatan mutasinya berbunyi "Saldo
     * bergerak selama penghitungan: layar menunjukkan 100, saldo saat disimpan 20" — 100 itu
     * saldo Cabang A. Mutasi salah-cabang datang dengan alasan yang terbaca WAJAR, dan enam
     * bulan kemudian keterangan itu dipakai memutuskan siapa yang salah.
     *
     * #[Locked] karena ini penentu tujuan penyimpanan: kalau klien bisa mengubahnya, seluruh
     * penjagaan di simpan() bisa dilepas dari muatan permintaan.
     *
     * TIDAK PERNAH disetel di render(). Kalau disetel saat render, lembar terkunci ke cabang
     * pertama yang kebetulan dibuka walau belum ada satu angka pun — pemilik yang salah
     * memilih cabang lalu terjebak, dan satu-satunya jalan keluarnya adalah membuang
     * pekerjaan yang bahkan belum ada.
     */
    #[Locked]
    public ?string $outletTerkunci = null;

    /**
     * Outlet yang TADI dicoba dipilih saat lembar sudah terkunci.
     *
     * Bukan sumber kebenaran apa pun — hanya penggerak blok peringatan di layar ("angkanya
     * milik Cabang A; buang dan pindah, atau tetap di sini?"). Namanya dirender lewat
     * namaOutletDiminta(), yang hanya mengakui id yang benar-benar ada di outletTersedia();
     * id di luar itu (mis. dikirim langsung ke properti) tidak boleh membuat nama outlet
     * tenant lain tercetak di layar pemilik.
     */
    public ?string $outletDiminta = null;

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
     *
     * `outletId` TIDAK ada di daftar ini, dan itu bukan kelalaian.
     *
     * Pergantian outlet punya penanganannya sendiri di updatedOutletId(): pergantian yang
     * DITOLAK (lembar sudah terkunci) tidak boleh mereset halaman — pemilik yang salah
     * menyentuh dropdown saat sedang di halaman 7 dari 12 akan terlempar ke halaman 1
     * dan harus mencari kembali tempatnya di lembar yang sama.
     */
    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'jenis', 'status'], true)) {
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
        // Dipanggil lebih dulu, untuk SEMUA bentuk pembaruan $fisik (termasuk penggantian
        // seluruh array, yang datang tanpa $kunci): angka pertama yang masuk menentukan
        // cabang tempat lembar ini dihitung, dan baris terakhir yang dikosongkan
        // membebaskannya kembali.
        $this->segarkanKunciOutlet();

        if ($kunci === null) {
            /*
             * Penggantian seluruh array $fisik datang TANPA $kunci, jadi jalur per-baris di
             * bawah tidak pernah jalan — dan tanpa penyelarasan di sini, kolom yang
             * dikosongkan lewat bentuk itu tidak pernah ditandai, sehingga rekamAngkaTerbaca()
             * mengisinya ulang dan catatan auditnya kembali bisa mengarang.
             *
             * Yang diselaraskan hanya arah "menjadi kosong": baris yang justru terisi lewat
             * bentuk ini tetap mengikuti `??=` di render, karena angka yang terbaca di layar
             * untuk baris itu belum tentu berubah.
             */
            foreach ($this->sistemSaatDibuka as $kunciTerekam => $tidakDipakai) {
                if (! $this->diisi($this->fisik[$kunciTerekam] ?? null)) {
                    unset($this->sistemSaatDibuka[$kunciTerekam]);
                    $this->dibersihkan[$kunciTerekam] = true;
                }
            }

            return;
        }

        if (blank($nilai)) {
            unset($this->sistemSaatDibuka[$kunci]);

            // Ditandai, bukan cuma di-unset: render sesudah ini akan mengisinya ulang kalau
            // barisnya masih tampak, dan pembersihannya jadi tidak pernah terjadi.
            $this->dibersihkan[$kunci] = true;

            return;
        }

        if (isset($this->dibersihkan[$kunci])) {
            // Diketik LAGI sesudah dikosongkan: yang berlaku angka yang sedang tampak
            // sekarang, bukan angka dari sebelum baris itu dikosongkan.
            $this->sistemSaatDibuka[$kunci] = (float) ($this->semuaBaris()->firstWhere('kunci', $kunci)['sistem'] ?? 0);
            unset($this->dibersihkan[$kunci]);

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
     * Baris yang SENGAJA dikosongkan dilewati, dan itu yang menutup cacat berat.
     *
     * Perekaman di sini memang harus mencakup baris yang BELUM diketik — `wire:model`
     * tertunda, jadi angka yang diketik pemilik baru tiba bersamaan tombol simpan, dan
     * kalau baris kosong tidak direkam maka tidak ada satu pun angka "yang terbaca di
     * layar" yang tersimpan. (Saya sempat menjaganya dengan `$fisik` terisi, dan itu
     * mematikan seluruh fitur ini — ada ujinya:
     * OpnameStokTest::test_angka_sistem_yang_tampil_di_layar_tetap_tercatat…)
     *
     * Tapi karena Livewire selalu render() sesudah setiap pembaruan, `unset()` di
     * updatedFisik() dulu langsung ditimpa balik pada siklus yang sama selama barisnya masih
     * tampak — pembersihannya tidak pernah benar-benar terjadi. Akibatnya, TANPA pindah
     * cabang sama sekali: ketik 50 saat sistem 100, kosongkan kolomnya, ada penjualan
     * (100→70), ketik ulang ⇒ catatan mutasi berbunyi "layar menunjukkan 100, saldo saat
     * disimpan 70". Angka stoknya benar; keterangannya karangan.
     *
     * Karena itu pembersihan dicatat di `$dibersihkan`, bukan hanya dengan `unset`: sesudah
     * pemilik mengosongkan sebuah kolom, lembar berhenti merekam untuk baris itu sampai ia
     * MENGETIKNYA LAGI — dan saat itulah angka yang benar-benar sedang tampak yang dipakai.
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     */
    private function rekamAngkaTerbaca(Collection $baris): void
    {
        foreach ($baris as $satu) {
            // Baris yang SENGAJA dikosongkan dilewati — lihat $dibersihkan.
            if (isset($this->dibersihkan[$satu['kunci']])) {
                continue;
            }

            $this->sistemSaatDibuka[$satu['kunci']] ??= (float) $satu['sistem'];
        }
    }

    /* ── Kunci outlet ────────────────────────────────────────────────────── */

    /**
     * Menyelaraskan kunci outlet dengan keadaan lembar.
     *
     * Satu-satunya tempat kunci itu LAHIR, dan sengaja bukan render(): yang mengunci adalah
     * ANGKA yang diketik, bukan halaman yang dibuka. Lembar yang masih kosong harus bebas
     * pindah cabang — pemilik yang salah memilih cabang di dropdown belum kehilangan apa
     * pun, dan menguncinya di situ hanya menjebaknya.
     *
     * Sebaliknya, baris terakhir yang DIKOSONGKAN membebaskan kunci: tidak ada lagi angka
     * yang bisa jatuh ke cabang yang salah, jadi tidak ada lagi yang perlu dijaga.
     */
    private function segarkanKunciOutlet(): void
    {
        if ($this->jumlahTerisi() === 0) {
            $this->outletTerkunci = null;

            return;
        }

        // ??= — cabangnya ditentukan angka PERTAMA yang masuk, dan tidak pernah bergeser
        // sesudahnya. Kalau nilainya diperbarui tiap ketikan, dropdown yang diganti di
        // tengah penghitungan akan memindahkan seluruh hasil hitung ke cabang lain — yaitu
        // cacat yang justru sedang ditutup.
        $this->outletTerkunci ??= $this->outletTerpakai();
    }

    /**
     * Pergantian outlet dari <select> (dan dari tombol Back/Forward peramban, karena
     * outletId ber-#[Url]).
     *
     * Ditolak kalau lembar sudah terkunci: yang dibuang bukan "sebuah pilihan", tapi
     * setengah jam penghitungan. Pemiliknya yang memutuskan lewat blok peringatan —
     * pindahOutlet() (buang lalu pindah) atau tetapDiOutlet() (batal pindah).
     */
    public function updatedOutletId(?string $baru): void
    {
        if ($this->outletTerkunci !== null) {
            if ($baru === $this->outletTerkunci) {
                // Nilai yang sama dengan cabang tempat angkanya dihitung: tidak ada
                // pergantian yang benar-benar terjadi, jadi tidak ada yang boleh
                // dibersihkan. Membersihkan $sistemSaatDibuka di sini akan menghapus jejak
                // audit lembar yang sedang dikerjakan tanpa satu pun cabang berpindah.
                return;
            }

            $this->outletDiminta = $baru;

            // Dikembalikan supaya layar TIDAK pernah menampilkan cabang yang berbeda dari
            // cabang tempat angkanya akan disimpan. Layar yang menampilkan Cabang B
            // sementara angkanya milik Cabang A adalah rasa lain dari penyakit yang sama:
            // mutasi yang tidak disaksikan pemiliknya.
            $this->outletId = $this->outletTerkunci;

            // TANPA resetPage(): penolakan tidak boleh menghukum pemilik dengan
            // memindahkannya ke halaman 1 dari lembar 12 halaman.
            return;
        }

        /*
         * Lembar kosong: pindah diizinkan, dan sisa-sisa lembar sebelumnya dibuang.
         *
         * $sistemSaatDibuka WAJIB ikut dibersihkan, dan inilah penutup cacat kedua: nilainya
         * diisi saat baris DIRENDER, jadi hanya dengan MELIHAT Cabang A (tanpa mengetik satu
         * angka pun) saldo Cabang A sudah masuk ke situ, lalu `??=` di updatedFisik()
         * mempertahankannya saat pemilik menghitung di Cabang B. Saldo B-nya benar, tapi
         * kartu stok B memuat "layar menunjukkan 100" yang tidak pernah terjadi — catatan
         * audit karangan tanpa pemiliknya melakukan kesalahan apa pun, dan tidak ada satu
         * pun uji stok yang gagal karenanya karena angkanya benar.
         *
         * $alasan/$catatan dibersihkan tanpa menghilangkan pekerjaan: alasan tanpa angka
         * fisik tidak berarti apa-apa, dan $fisik memang sudah kosong di cabang ini.
         */
        $this->alasan = [];
        $this->catatan = [];
        $this->sistemSaatDibuka = [];
        $this->dibersihkan = [];
        $this->outletDiminta = null;
        $this->resetValidation();
        $this->sebagianTersimpan = false;
        $this->resetPage();
    }

    /**
     * Buang hasil hitung, lalu pindah cabang. SATU-SATUNYA jalur pindah yang membuang.
     *
     * Dipisahkan dari dropdown dengan sengaja: membuang setengah jam penghitungan harus
     * berupa tindakan yang disebut namanya, bukan efek samping dari menyentuh sebuah
     * pilihan.
     */
    public function pindahOutlet(string $tujuan): void
    {
        $sebelumnya = $this->outletId;
        $this->outletId = $tujuan;

        /*
         * Gerbang aksesnya tetap outletTerpakai() — satu pemeriksa untuk seluruh halaman
         * ini, dan ia yang menjatuhkan 403 untuk outlet tenant lain. Dipanggil SEBELUM satu
         * angka pun dibuang: kalau tujuannya tidak sah, permintaannya berhenti dan hasil
         * hitung yang sah tetap utuh.
         */
        $sah = $this->outletTerpakai();

        if ($sah === null) {
            $this->outletId = $sebelumnya;

            return;
        }

        $this->fisik = [];
        $this->alasan = [];
        $this->catatan = [];
        $this->sistemSaatDibuka = [];
        $this->dibersihkan = [];
        $this->outletTerkunci = null;
        $this->outletDiminta = null;
        $this->resetValidation();
        $this->sebagianTersimpan = false;

        // $sah, bukan $tujuan: untuk peran yang terkunci ke satu outlet, outletTerpakai()
        // mengabaikan nilai dari klien dan tetap mengembalikan outletnya sendiri.
        $this->outletId = $sah;

        $this->resetPage();

        $this->toast('Hasil hitung dibuang. Lembar sekarang untuk outlet yang baru dipilih.', 'peringatan');
    }

    /** Batal pindah cabang: peringatannya ditutup, lembarnya tidak disentuh sama sekali. */
    public function tetapDiOutlet(): void
    {
        $this->outletDiminta = null;
    }

    /**
     * Nama outlet yang tadi diminta, untuk blok peringatan.
     *
     * Hanya id yang benar-benar ada di outletTersedia() yang diakui. $outletDiminta bisa
     * berisi id apa pun — ia lahir dari nilai yang dikirim klien, dan jalur penolakan
     * sengaja TIDAK memanggil pemeriksa akses (menolak tidak boleh berubah menjadi 403).
     * Kalau namanya dicari langsung dengan Outlet::find(), id outlet tenant lain akan
     * membuat nama usaha orang lain tercetak di layar pemilik.
     */
    private function namaOutletDiminta(): ?string
    {
        if ($this->outletDiminta === null) {
            return null;
        }

        foreach ($this->outletTersedia() as $outlet) {
            if ($outlet['id'] === $this->outletDiminta) {
                return $outlet['nama'];
            }
        }

        return null;
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

        /*
         * Lapisan terakhir kunci outlet: MENOLAK, bukan diam-diam menulis.
         *
         * Wajib ada walau UI juga menolak, karena outletId ber-#[Url(as: 'outlet')] —
         * nilainya juga berubah dari tombol Back/Forward peramban (popstate), bukan hanya
         * dari <select>. Jalur mana pun yang melewati updatedOutletId() berhenti di sini.
         *
         * Menolak, bukan menyimpan ke $outletTerkunci: menulis ke Cabang A sementara layar
         * menampilkan Cabang B adalah rasa lain dari penyakit yang sama — mutasi yang tidak
         * disaksikan pemiliknya. Tidak satu baris ditulis, tidak satu angka dihapus.
         *
         * TIDAK memakai addError('outletId', …): blok ringkasan galat di Blade memasangkan
         * kunci galat dengan nama barang lewat str($kunci)->after('.'), dan Str::after
         * mengembalikan seluruh string kalau titiknya tidak ada — jadi kunci tanpa titik
         * tampil sebagai "Baris lain: …", kemunduran yang persis pernah diperbaiki di berkas
         * ini. Peringatannya lewat toast + blok $outletDiminta.
         */
        if ($this->outletTerkunci !== null && $this->outletTerkunci !== $outletId) {
            $this->outletDiminta = $outletId;
            $this->outletId = $this->outletTerkunci;

            $this->toast(
                'Angka di lembar ini dihitung untuk outlet lain, jadi belum ada yang disimpan. Pilih buang-lalu-pindah, atau tetap di outlet tempat angkanya dihitung.',
                'peringatan',
            );

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

        /*
         * Kunci dilepas hanya kalau lembarnya benar-benar habis.
         *
         * Baris yang GAGAL dibiarkan terisi (supaya bisa dicoba lagi tanpa dihitung ulang),
         * jadi kuncinya TETAP di cabang tempat angkanya dihitung — dan itu benar: angka yang
         * masih tertinggal di kolom itu masih milik cabang tersebut.
         */
        if ($this->jumlahTerisi() === 0) {
            $this->outletTerkunci = null;
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

    /**
     * Nol dianggap TERISI; hanya null/kosong yang berarti belum dihitung.
     *
     * Spasi dihitung KOSONG, dan itu wajib: `blank()` di updatedFisik() serta Alpine di
     * resources/js/opname.js (memakai `.trim()`) sudah menganggapnya kosong. Selama fungsi
     * ini tidak ikut memangkas, satu spasi yang tidak sengaja tertekan membuat server
     * berkata "1 baris sudah dihitung" sementara layar tidak menampilkan satu kotak terisi
     * pun — kolomnya tidak diwarnai, lencana "wajib pilih alasan" tidak muncul, dan lembar
     * terkunci ke cabang itu. Pemiliknya harus menyisir 12 halaman mencari karakter yang
     * tidak kelihatan, atau membuang seluruh hasil hitungnya. Tiga tempat memutuskan
     * "terisi", dan ketiganya harus sepakat.
     *
     * Nol tetap terisi: "0" adalah pernyataan bahwa raknya kosong — justru selisih terbesar
     * yang bisa ada. Memangkas TIDAK boleh mengubah itu, karena trim('0') tetap '0'.
     */
    private function diisi(mixed $nilai): bool
    {
        if ($nilai === null) {
            return false;
        }

        return ! is_string($nilai) || trim($nilai) !== '';
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

        return view('livewire.pages.owner.stok.opname', [
            'daftar' => $daftar,
            'namaPerKunci' => $this->namaPerKunci($daftar->items()),
            'alasanTersedia' => AlasanOpname::pilihan(),
            'jumlahTerisi' => $this->jumlahTerisi(),
            'outletTersedia' => auth()->user()->scopedOutletId() === null ? $this->outletTersedia() : [],
            'outletDipakai' => $this->outletTerpakai(),
            // Untuk blok peringatan "angkanya milik cabang lain". null berarti tidak ada
            // permintaan pindah yang tertahan ATAU id-nya bukan outlet yang boleh dilihat
            // pengguna ini — dan dalam kedua keadaan itu tidak ada nama yang boleh dicetak.
            'namaOutletDiminta' => $this->namaOutletDiminta(),
        ]);
    }
}
