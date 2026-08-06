<?php

namespace App\Livewire\Pages\Owner;

use App\Actions\Purchase\CatatPembelianAction;
use App\Actions\Stock\SusunBarisStokAction;
use App\Enums\Satuan;
use App\Livewire\Concerns\MengirimToast;
use App\Livewire\Concerns\TerikatTenant;
use App\Models\Outlet;
use App\Models\Supplier;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Catat nota belanja: barang yang baru datang dari grosir/pasar.
 *
 * Barisnya berasal dari sumber yang SAMA dengan layar stok & lembar hitung stok
 * (SusunBarisStokAction), jadi barang yang bisa dihitung juga barang yang bisa dibeli —
 * termasuk yang belum punya baris `stocks` sama sekali. Menu berbasis resep tidak ikut:
 * yang dibeli untuk menu adalah BAHAN BAKUNYA, dan menaikkan stok menu jadi berarti
 * menambah angka yang tidak pernah berkurang saat menunya terjual.
 *
 * TIGA HAL YANG MUDAH SALAH DI LAYAR INI:
 *
 * 1. **Jumlah diketik dalam satuan BELI (dus), stok bertambah dalam satuan DASAR (pcs).**
 *    Faktornya dibaca dari master (`isi_per_satuan`) dan TIDAK ada medannya di sini —
 *    dua tempat yang mengisi faktor konversi berarti dua kebenaran, dan blok "Harus
 *    belanja" di layar stok hanya membaca yang di master.
 *
 * 2. **Harga yang diketik adalah harga per satuan BELI**, tapi yang disimpan ke master
 *    adalah harga per satuan DASAR. Lihat CatatPembelianAction.
 *
 * 3. **Outlet terkunci ke tempat baris pertama diketik.** Kunci baris adalah id barang,
 *    dan barang bersifat TENANT — barang yang sama punya kunci yang sama di semua cabang.
 *    Tanpa kunci ini, dropdown yang diganti di tengah pengetikan akan memasukkan seluruh
 *    belanjaan ke cabang yang salah tanpa satu pun galat. Mekanismenya disalin utuh dari
 *    lembar hitung stok, termasuk lapisan penolak di simpan(): `outletId` ber-#[Url],
 *    jadi tombol Back peramban juga mengubahnya tanpa lewat updatedOutletId().
 */
#[Layout('layouts.aplikasi')]
class PembelianBaru extends Component
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

    /**
     * Jumlah yang dibeli, dalam satuan BELI, di-key id barang.
     *
     * Di-key id barang (bukan indeks baris halaman) supaya angka yang sudah diketik
     * bertahan saat pindah halaman dan saat saringan berubah — sama seperti lembar hitung
     * stok. Nota belanja bulanan kelontong bisa 40 barang; dengan 10 baris per halaman itu
     * 4 halaman, dan angka yang hilang saat berpindah halaman berarti nota diketik ulang
     * dari awal.
     *
     * @var array<string, mixed>
     */
    public array $jumlah = [];

    /** @var array<string, mixed> harga per satuan BELI, di-key id barang */
    public array $harga = [];

    /** Teks bebas dengan saran nama yang sudah pernah dipakai; boleh dikosongkan. */
    public string $beliDari = '';

    public string $tanggal = '';

    public ?string $diskon = null;

    public ?string $ongkosKirim = null;

    public string $catatan = '';

    /**
     * "Barangnya sudah saya terima" (true, BAWAAN) / "Barangnya belum datang" (false).
     *
     * Bawaannya true, dan itu keputusan yang menentukan: belanja warung yang biasa dicatat
     * SESUDAH barangnya diturunkan dari motor. Bawaan sebaliknya akan mengubah setiap nota
     * biasa menjadi nota menggantung yang stoknya tidak pernah masuk, dan pemiliknya baru
     * menyadarinya saat layar kasir mengabari "Habis" untuk rak yang penuh.
     *
     * Sebaliknya, keadaan yang jarang tapi nyata — barang dipesan hari ini, datang tujuh
     * hari kemudian — dulu tidak punya wujud sama sekali: notanya langsung menambah stok,
     * jadi selama seminggu saldo mengaku ada barang yang belum tiba di rak dan kasir
     * dikabari "Aman" untuk barang yang raknya kosong.
     *
     * TIDAK berbintang wajib: radio yang punya bawaan tidak pernah bisa kosong, jadi
     * validatornya bukan `required` (lihat CLAUDE.md — bintang hanya untuk yang benar-benar
     * required).
     */
    public bool $sudahDatang = true;

    /**
     * Outlet tempat nota ini DIKETIK — bukan outlet yang sedang dipilih di dropdown.
     *
     * #[Locked] karena ia penentu tujuan penyimpanan: kalau klien bisa mengubahnya,
     * seluruh penjagaan di simpan() bisa dilepas dari muatan permintaan.
     *
     * TIDAK PERNAH disetel di render() — nota yang masih kosong harus bebas pindah cabang,
     * karena pemilik yang salah memilih cabang belum kehilangan apa pun dan menguncinya di
     * situ hanya menjebaknya.
     */
    #[Locked]
    public ?string $outletTerkunci = null;

    /** Outlet yang tadi dicoba dipilih saat nota sudah berisi baris; penggerak peringatan. */
    public ?string $outletDiminta = null;

    public function mount(): void
    {
        if (blank($this->outletId)) {
            $this->outletId = $this->outletBawaan();
        }

        $this->tanggal = now()->toDateString();
    }

    /**
     * Saringan & paginasi TIDAK PERNAH menyentuh $jumlah/$harga.
     *
     * `outletId` sengaja TIDAK ada di daftar ini: pergantian outlet punya penanganannya
     * sendiri di updatedOutletId(), dan pergantian yang DITOLAK tidak boleh mereset
     * halaman — pemilik yang salah menyentuh dropdown saat berada di halaman 3 akan
     * terlempar ke halaman 1 dan harus mencari kembali tempatnya.
     */
    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'jenis'], true)) {
            $this->resetPage();
        }
    }

    /** Angka pertama yang masuk mengunci nota ke cabang tempat ia diketik. */
    public function updatedJumlah(): void
    {
        $this->segarkanKunciOutlet();
    }

    /* ── Kunci outlet ────────────────────────────────────────────────────── */

    private function segarkanKunciOutlet(): void
    {
        if ($this->jumlahTerisi() === 0) {
            // Baris terakhir yang dikosongkan membebaskan kuncinya: tidak ada lagi angka
            // yang bisa jatuh ke cabang yang salah.
            $this->outletTerkunci = null;

            return;
        }

        // ??= — cabangnya ditentukan baris PERTAMA dan tidak pernah bergeser sesudahnya.
        $this->outletTerkunci ??= $this->outletTerpakai();
    }

    /**
     * Pergantian outlet dari <select> DAN dari tombol Back/Forward peramban (outletId
     * ber-#[Url], jadi popstate juga mengubahnya).
     *
     * Ditolak kalau notanya sudah berisi baris: yang dibuang bukan "sebuah pilihan", tapi
     * nota yang sudah setengah diketik. Pemiliknya yang memutuskan lewat blok peringatan.
     */
    public function updatedOutletId(?string $baru): void
    {
        if ($this->outletTerkunci !== null) {
            if ($baru === $this->outletTerkunci) {
                return;
            }

            $this->outletDiminta = $baru;

            // Dikembalikan supaya layar TIDAK pernah menampilkan cabang yang berbeda dari
            // cabang tempat notanya akan disimpan.
            $this->outletId = $this->outletTerkunci;

            // TANPA resetPage(): penolakan tidak boleh menghukum pemilik dengan
            // memindahkannya ke halaman 1.
            return;
        }

        $this->outletDiminta = null;
        $this->resetValidation();
        $this->resetPage();
    }

    /** Buang isian, lalu pindah cabang. SATU-SATUNYA jalur pindah yang membuang. */
    public function pindahOutlet(string $tujuan): void
    {
        $sebelumnya = $this->outletId;
        $this->outletId = $tujuan;

        // Gerbang aksesnya tetap outletTerpakai() — ia yang menjatuhkan 403 untuk outlet
        // merchant lain. Dipanggil SEBELUM satu angka pun dibuang.
        $sah = $this->outletTerpakai();

        if ($sah === null) {
            $this->outletId = $sebelumnya;

            return;
        }

        $this->jumlah = [];
        $this->harga = [];
        $this->outletTerkunci = null;
        $this->outletDiminta = null;
        $this->resetValidation();

        // $sah, bukan $tujuan: untuk peran yang terkunci ke satu outlet, outletTerpakai()
        // mengabaikan nilai dari klien dan tetap mengembalikan outletnya sendiri.
        $this->outletId = $sah;

        $this->resetPage();

        $this->toast('Isian dibuang. Nota sekarang untuk outlet yang baru dipilih.', 'peringatan');
    }

    /** Batal pindah cabang: peringatannya ditutup, isiannya tidak disentuh. */
    public function tetapDiOutlet(): void
    {
        $this->outletDiminta = null;
    }

    /**
     * Nama outlet yang tadi diminta, untuk blok peringatan.
     *
     * Hanya id yang benar-benar ada di outletTersedia() yang diakui: $outletDiminta lahir
     * dari nilai yang dikirim klien, dan mencarinya langsung dengan Outlet::find() akan
     * membuat nama usaha merchant lain tercetak di layar pemilik.
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
     * Menyimpan seluruh nota.
     *
     * Divalidasi LEBIH DULU untuk semua baris terisi, baru dicatat: nota adalah satu
     * dokumen, dan tidak boleh ada baris yang sudah masuk stok saat pemiliknya melihat
     * pesan galat — ia akan mengulang, dan baris yang sudah masuk terhitung dua kali.
     */
    public function simpan(): void
    {
        $outletId = $this->outletTerpakai();

        if ($outletId === null) {
            $this->toast('Pilih outlet dulu sebelum menyimpan nota.', 'peringatan');

            return;
        }

        /*
         * Lapisan terakhir kunci outlet: MENOLAK, bukan diam-diam menulis.
         *
         * Wajib ada walau UI juga menolak, karena outletId ber-#[Url(as: 'outlet')] —
         * nilainya juga berubah dari tombol Back/Forward peramban (popstate), bukan hanya
         * dari <select>. Menulis ke Cabang A sementara layar menampilkan Cabang B adalah
         * rasa lain dari penyakit yang sama: barang masuk ke cabang yang tidak disaksikan
         * pemiliknya.
         */
        if ($this->outletTerkunci !== null && $this->outletTerkunci !== $outletId) {
            $this->outletDiminta = $outletId;
            $this->outletId = $this->outletTerkunci;

            $this->toast(
                'Nota ini diketik untuk outlet lain, jadi belum ada yang disimpan. Pilih buang-lalu-pindah, atau tetap di outlet tempat notanya diketik.',
                'peringatan',
            );

            return;
        }

        // Sumber baris TANPA saringan: yang disimpan adalah semua yang diketik, bukan
        // yang sedang tampak di layar.
        $semua = $this->semuaBaris()->keyBy('kunci');
        $terisi = collect($this->jumlah)->filter(fn (mixed $nilai) => $this->diisi($nilai));

        if ($terisi->isEmpty()) {
            // Juga penjaga tombol simpan yang tertekan dua kali: penyimpanan yang berhasil
            // mengosongkan isian, jadi tekanan kedua tidak menemukan satu baris pun.
            $this->toast('Belum ada barang yang diisi jumlahnya.', 'peringatan');

            return;
        }

        $this->periksa($terisi, $semua);

        $muatan = [
            'beli_dari' => $this->beliDari,
            'tanggal' => $this->tanggal,
            'diskon' => $this->diskon,
            'ongkos_kirim' => $this->ongkosKirim,
            'catatan' => $this->catatan,
            // Penentu apakah stok ikut bertambah sekarang. Aksinya yang memutuskan, bukan
            // layar ini: satu-satunya jalur stok dari pembelian ada di TerimaPembelianAction.
            'sudah_datang' => $this->sudahDatang,
            'baris' => $terisi->map(fn (mixed $nilai, string $kunci) => [
                'product_id' => $semua[$kunci]['product_id'],
                'raw_material_id' => $semua[$kunci]['raw_material_id'],
                'qty_beli' => $nilai,
                'harga_satuan' => $this->harga[$kunci] ?? 0,
            ])->values()->all(),
        ];

        $nota = app(CatatPembelianAction::class)->execute(
            Outlet::query()->findOrFail($outletId),
            auth()->user(),
            $muatan,
        );

        // Diingat sebelum isiannya dikembalikan ke bawaan, karena pesan di bawah bergantung
        // padanya — dan pesan yang mengaku "stok sudah bertambah" untuk nota yang barangnya
        // belum datang adalah kebohongan yang membuat seluruh pesan berikutnya tidak
        // dipercaya lagi.
        $sudahDatang = $this->sudahDatang;

        // Dikosongkan supaya tekanan tombol kedua tidak mencatat nota kembar, dan supaya
        // nota berikutnya (belanja di dua grosir dalam satu hari itu biasa) mulai bersih.
        $this->jumlah = [];
        $this->harga = [];
        $this->diskon = null;
        $this->ongkosKirim = null;
        $this->catatan = '';
        $this->outletTerkunci = null;
        $this->outletDiminta = null;
        // Dikembalikan ke bawaan: nota berikutnya biasanya belanja biasa yang barangnya
        // sudah dibawa pulang, dan pilihan yang MENEMPEL dari nota sebelumnya membuat
        // belanja hari itu diam-diam tidak masuk stok.
        $this->sudahDatang = true;
        $this->resetValidation();
        $this->resetPage();

        $this->toast(
            $sudahDatang
                ? 'Nota '.$nota->nomor_po.' tersimpan. Stok sudah bertambah.'
                : 'Nota '.$nota->nomor_po.' tersimpan sebagai belum datang. Stok belum bertambah — tandai datang begitu barangnya sampai.',
        );
    }

    /**
     * Validasi seluruh baris terisi sekaligus.
     *
     * @param  Collection<string, mixed>  $terisi
     * @param  Collection<string, array<string, mixed>>  $semua
     */
    private function periksa(Collection $terisi, Collection $semua): void
    {
        $aturan = [
            'diskon' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'ongkosKirim' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'tanggal' => ['required', 'date'],
            'beliDari' => ['nullable', 'string', 'max:255'],
            'catatan' => ['nullable', 'string', 'max:1000'],
            // BUKAN 'required': pilihannya punya bawaan, jadi ia tidak pernah bisa kosong —
            // dan medan yang berbintang wajib padahal tidak pernah bisa kosong membuat
            // bintangnya berhenti dipercaya di seluruh formulir (CLAUDE.md).
            'sudahDatang' => ['boolean'],
        ];

        $atribut = [
            'diskon' => 'diskon',
            'ongkosKirim' => 'ongkos kirim',
            'tanggal' => 'tanggal nota',
            'beliDari' => 'beli dari',
            'catatan' => 'catatan',
            'sudahDatang' => 'keterangan barang sudah datang',
        ];

        foreach ($terisi->keys() as $kunci) {
            // gt:0 — jumlah minus adalah salah ketik, dan nol berarti barisnya tidak
            // dibeli. Keduanya tidak boleh jadi mutasi stok.
            $aturan['jumlah.'.$kunci] = ['numeric', 'gt:0', 'max:99999999'];
            // Harga WAJIB, dan nol sah: nol adalah pernyataan "bonus", sedangkan kosong
            // berarti belum diisi. Membiarkan kosong lolos sebagai nol menghapus harga
            // beli barangnya di master.
            $aturan['harga.'.$kunci] = ['required', 'numeric', 'min:0', 'max:9999999999'];
            $atribut['jumlah.'.$kunci] = 'jumlah beli';
            $atribut['harga.'.$kunci] = 'harga beli';
        }

        /*
         * Pesan bawaan Laravel BERBAHASA INGGRIS di repo ini (APP_LOCALE=en, dan tidak ada
         * lang/id), jadi tanpa blok ini kotak harga yang lupa diisi berbunyi
         * "The harga beli field is required." — terpotret begitu di tangkapan pratinjau.
         * Setengah Inggris setengah Indonesia di layar pemilik warung bukan cuma jelek: ia
         * membuat kalimatnya berhenti bisa dibaca sama sekali.
         *
         * Aturannya TIDAK diubah — hanya teks yang dilihat orang. `:Attribute` memakai nama
         * dari $atribut ("harga beli", "jumlah beli"), yang sudah berbahasa Indonesia.
         */
        $pesan = [
            'required' => ':Attribute wajib diisi.',
            'numeric' => ':Attribute harus berupa angka.',
            'gt' => ':Attribute harus lebih dari :value.',
            'min' => ':Attribute tidak boleh kurang dari :min.',
            'max.numeric' => ':Attribute kelewat besar — periksa lagi angkanya.',
            'max.string' => ':Attribute paling panjang :max huruf.',
            'date' => ':Attribute harus berupa tanggal yang benar.',
            'string' => ':Attribute harus berupa teks.',
        ];

        $validator = Validator::make(
            [
                'jumlah' => $this->jumlah,
                'harga' => $this->harga,
                'diskon' => $this->diskon,
                'ongkosKirim' => $this->ongkosKirim,
                'tanggal' => $this->tanggal,
                'beliDari' => $this->beliDari,
                'catatan' => $this->catatan,
                'sudahDatang' => $this->sudahDatang,
            ],
            $aturan,
            $pesan,
            $atribut,
        );

        $validator->after(function ($validator) use ($terisi, $semua): void {
            $subtotal = 0.0;

            foreach ($terisi as $kunci => $nilai) {
                $baris = $semua->get($kunci);

                if ($baris === null) {
                    // Barangnya dihapus/dimatikan pelacakannya saat nota sedang diketik.
                    // Dilaporkan, bukan dilewati diam-diam: angka yang sudah diketik orang
                    // tidak boleh hilang tanpa pemberitahuan.
                    $validator->errors()->add('jumlah.'.$kunci, 'Barang ini tidak ada lagi di daftar stok outlet ini.');

                    continue;
                }

                if (! is_numeric($nilai)) {
                    continue;
                }

                $harga = $this->harga[$kunci] ?? null;

                if (is_numeric($harga) && (float) $harga >= 0 && (float) $nilai > 0) {
                    $subtotal += (float) $nilai * (float) $harga;
                }

                /*
                 * Pecahan hanya untuk satuan yang memang bisa dipecah.
                 *
                 * 2,5 kg beras masuk akal; 2,5 dus tidak, dan angka itu akan menjadi 30
                 * pcs di kartu stok tanpa ada dus setengah yang pernah dibawa pulang dari
                 * grosir. Satuannya dibaca dari master (kolom `satuan`), sama dengan yang
                 * dipakai blok "Harus belanja" di layar stok.
                 */
                $satuan = Satuan::tryFrom((string) ($baris['satuan'] ?? ''));

                if ($satuan !== null && ! $satuan->allowsFraction() && fmod((float) $nilai, 1.0) !== 0.0) {
                    $validator->errors()->add(
                        'jumlah.'.$kunci,
                        'Jumlah "'.$baris['nama'].'" harus bilangan bulat karena satuannya '.$satuan->label().'.',
                    );
                }
            }

            /*
             * Diskon lebih besar daripada belanjaannya selalu salah ketik (54.000 diketik
             * di kolom diskon, bukan di kolom harga), dan hasilnya total nota NEGATIF —
             * uang masuk menurut catatan, padahal pemiliknya baru saja membayar.
             */
            if (is_numeric($this->diskon) && (float) $this->diskon > $subtotal + 0.005) {
                $validator->errors()->add('diskon', 'Diskon tidak boleh lebih besar daripada total belanjaannya.');
            }
        });

        // Melempar ValidationException — Livewire menangkapnya dan mengisi kantong galat,
        // dan tidak ada satu baris pun yang tersimpan sebelum ini lolos.
        $validator->validate();
    }

    /* ── Daftar barang ───────────────────────────────────────────────────── */

    /** @return Collection<int, array<string, mixed>> */
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

        return app(SusunBarisStokAction::class)->execute($outletId, $this->jenis, trim($this->cari));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function halamankan(Collection $baris): LengthAwarePaginator
    {
        // Angkanya tidak diketik di sini supaya seluruh daftar di aplikasi berpindah
        // bersamaan kalau nanti diubah.
        $perHalaman = (int) config('nampan.per_halaman');
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
     * Nama pemasok yang sudah pernah dipakai, untuk saran di medan "Beli dari".
     *
     * Tabel suppliers dipakai, layarnya tidak: pemilik warung tidak akan mengisi formulir
     * master pemasok sebelum mencatat belanja. Nama baru cukup diketik dan barisnya lahir
     * sendiri saat nota disimpan.
     *
     * @return array<int, string>
     */
    private function saranPemasok(): array
    {
        return Supplier::query()
            ->where('is_active', true)
            ->orderBy('nama')
            ->pluck('nama')
            ->all();
    }

    /**
     * Nol dianggap KOSONG di sini, berbeda dari lembar hitung stok.
     *
     * Di hitung stok, "0" adalah pernyataan bahwa raknya kosong — justru selisih terbesar
     * yang bisa ada. Di nota belanja, "0" berarti barang itu tidak dibeli, dan
     * memasukkannya sebagai baris nota akan mencetak baris tanpa barang beserta mutasi
     * stok bernilai nol di kartu stok.
     *
     * Spasi dihitung kosong: satu spasi yang tidak sengaja tertekan tidak boleh mengunci
     * nota ke sebuah cabang tanpa ada satu kotak pun yang terlihat terisi di layar.
     */
    private function diisi(mixed $nilai): bool
    {
        if ($nilai === null) {
            return false;
        }

        if (is_string($nilai)) {
            return trim($nilai) !== '';
        }

        return true;
    }

    /** Berapa baris yang sudah diketik — termasuk yang tidak sedang tampak di layar. */
    public function jumlahTerisi(): int
    {
        return collect($this->jumlah)->filter(fn (mixed $nilai) => $this->diisi($nilai))->count();
    }

    /**
     * Ringkasan uang untuk bar bawah: subtotal, diskon, ongkir, total.
     *
     * Dihitung di server dengan rumus yang SAMA dengan CatatPembelianAction
     * (total = subtotal − diskon + ongkir). Menghitungnya di Blade atau di Alpine berarti
     * dua rumus untuk satu angka, dan angka di layar yang berbeda dari angka yang
     * tersimpan adalah cara tercepat membuat orang berhenti memercayai keduanya.
     *
     * @param  Collection<int, array<string, mixed>>  $semua
     * @return array{baris: int, subtotal: float, diskon: float, ongkir: float, total: float}
     */
    private function ringkasan(Collection $semua): array
    {
        $indeks = $semua->keyBy('kunci');
        $subtotal = 0.0;
        $baris = 0;

        foreach ($this->jumlah as $kunci => $nilai) {
            if (! $this->diisi($nilai) || ! is_numeric($nilai) || ! $indeks->has($kunci)) {
                continue;
            }

            $baris++;
            $harga = $this->harga[$kunci] ?? null;

            if (is_numeric($harga)) {
                $subtotal += (float) $nilai * (float) $harga;
            }
        }

        $diskon = is_numeric($this->diskon) ? (float) $this->diskon : 0.0;
        $ongkir = is_numeric($this->ongkosKirim) ? (float) $this->ongkosKirim : 0.0;

        return [
            'baris' => $baris,
            'subtotal' => round($subtotal, 2),
            'diskon' => round($diskon, 2),
            'ongkir' => round($ongkir, 2),
            'total' => round($subtotal - $diskon + $ongkir, 2),
        ];
    }

    public function render()
    {
        $semua = $this->semuaBaris();

        return view('livewire.pages.owner.pembelian-baru', [
            'daftar' => $this->halamankan($this->barisTersaring()),
            'ringkasan' => $this->ringkasan($semua),
            'jumlahTerisi' => $this->jumlahTerisi(),
            'saranPemasok' => $this->saranPemasok(),
            // Nama barang per kunci untuk ringkasan galat. Diambil dari $semua (SELURUH
            // lembar), bukan dari halaman yang sedang tampak: validasi berjalan atas semua
            // baris terisi, jadi baris yang menahan simpan bisa berada di halaman lain —
            // dan pesan "jumlah beli harus lebih dari nol" tanpa nama barang tidak bisa
            // ditindaklanjuti tanpa menyisir halaman satu per satu. Tidak ada kueri baru:
            // koleksinya sudah dimuat untuk ringkasan uang di atas.
            'namaPerKunci' => $semua->pluck('nama', 'kunci')->all(),
            'outletTersedia' => auth()->user()->scopedOutletId() === null ? $this->outletTersedia() : [],
            'outletDipakai' => $this->outletTerpakai(),
            // null berarti tidak ada permintaan pindah yang tertahan ATAU id-nya bukan
            // outlet yang boleh dilihat pengguna ini — dan dalam kedua keadaan itu tidak
            // ada nama yang boleh dicetak.
            'namaOutletDiminta' => $this->namaOutletDiminta(),
        ]);
    }
}
