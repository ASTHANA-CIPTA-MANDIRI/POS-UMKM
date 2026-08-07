<?php

namespace App\Livewire\Pages\Owner\Pembelian;

use App\Actions\Pembelian\BatalkanPembelianAction;
use App\Actions\Pembelian\SimpanBuktiBelanjaAction;
use App\Actions\Pembelian\TerimaPembelianAction;
use App\Enums\DocumentStatus;
use App\Livewire\Concerns\MengirimToast;
use App\Livewire\Concerns\TerikatTenant;
use App\Models\Pembelian\PurchaseOrder;
use App\Models\Pembelian\PurchaseOrderItem;
use App\Models\Tenant\Outlet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Daftar nota belanja (pembelian) — riwayat barang masuk beserta uang yang keluar.
 *
 * TIGA keadaan nota, dan hanya tiga: "barang sudah datang" (Diterima), "belum datang"
 * (Dikirim), "dibatalkan". `Draft` TIDAK PERNAH ditulis aplikasi ini walaupun ia default
 * kolomnya di migrasi — nota berstatus draft berarti ada baris yang lahir tanpa lewat
 * CatatPembelianAction, dan itu anomali yang harus terlihat, bukan keadaan sah yang
 * diam-diam ikut dihitung. Nota lama berstatus tak dikenal tetap tampil apa adanya;
 * menyembunyikannya berarti menghilangkan mutasi stok yang sudah terjadi dari layar yang
 * seharusnya menjelaskannya.
 *
 * Nota yang dibatalkan TIDAK hilang dari daftar. Kalau ia hilang, kartu stok memuat mutasi
 * masuk dan keluar yang menunjuk dokumen yang tidak bisa dibuka siapa pun.
 *
 * Yang TIDAK dibangun di sini dan harus tetap begitu: apa pun yang MENAHAN penjualan.
 * Nota belum datang cuma berarti angkanya belum masuk saldo — kasir tidak pernah dikabari
 * barangnya tersedia, tapi ia juga tidak pernah dihalangi menjualnya (aturan 5 CLAUDE.md).
 */
#[Layout('layouts.aplikasi')]
class Pembelian extends Component
{
    use MengirimToast, TerikatTenant, WithFileUploads, WithPagination;

    private const NAMA_HALAMAN = 'page';

    /**
     * Rincian nota punya nomor halamannya SENDIRI.
     *
     * Kalau ikut 'page', membuka rincian nota di halaman 3 daftar akan melompatkan
     * daftarnya, dan menggeser halaman rincian ikut menggeser daftarnya — dua daftar
     * berbeda memakai satu penunjuk.
     */
    private const HALAMAN_RINCIAN = 'baris';

    /** Kosong = semua outlet. Nota dicatat per outlet karena stoknya per outlet. */
    #[Url(as: 'outlet')]
    public string $outletId = '';

    #[Url(as: 'cari')]
    public string $cari = '';

    /**
     * semua|diterima|belum|dibatalkan
     *
     * 'belum' = nota yang barangnya belum datang (DocumentStatus::Dikirim). Nilainya kata
     * warung, bukan nama status: yang membaca URL ini adalah pemiliknya, bukan gudang.
     */
    #[Url(as: 'status')]
    public string $status = 'semua';

    /** Nota yang rinciannya sedang dibuka; null berarti tidak ada. */
    public ?string $rincianId = null;

    /**
     * Foto kwitansi/struk yang baru dipilih untuk nota yang rinciannya sedang dibuka.
     *
     * Inilah nilai utama fiturnya: catat cepat di depan grosir, foto belakangan. Berlaku
     * untuk nota yang barangnya SUDAH datang MAUPUN yang BELUM — pemilik ingin menyimpan
     * kwitansinya begitu ia punya, bukan menunggu barangnya sampai lebih dulu.
     */
    public $bukti = null;

    public function mount(): void
    {
        // Manager outlet tidak punya pilihan: nilainya dikunci ke outletnya sendiri
        // supaya layar tidak pernah menampilkan pilihan yang akan diabaikan.
        $terkunci = auth()->user()->scopedOutletId();

        if ($terkunci !== null) {
            $this->outletId = $terkunci;
        }
    }

    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'status', 'outletId'], true)) {
            $this->resetPage();
            $this->rincianId = null;
            // Foto yang dipilih ikut dilepas: panelnya sudah tertutup, jadi berkas yang
            // masih menempel akan terpasang ke nota yang TIDAK sedang dilihat pemiliknya
            // pada penekanan tombol berikutnya.
            $this->bukti = null;
        }
    }

    /**
     * Gerbang akses outlet — dijalankan tiap render, bukan hanya saat mount.
     *
     * Pola yang sama dengan layar Stok & lembar hitung stok: pilihan outlet bisa berubah
     * kapan saja lewat properti, dan pemeriksaan yang hanya berjalan di mount berarti
     * outlet merchant lain cukup di-set belakangan untuk lolos.
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

    /* ── Rincian ─────────────────────────────────────────────────────────── */

    public function bukaRincian(string $id): void
    {
        $this->rincianId = $this->rincianId === $id ? null : $id;

        // Rinciannya selalu mulai dari halaman 1. Tanpa ini, halaman 3 dari nota
        // sebelumnya terbawa ke nota yang barisnya cuma dua, dan panelnya terbuka KOSONG
        // — terbaca sebagai "nota ini tidak ada isinya", padahal ada.
        $this->resetPage(self::HALAMAN_RINCIAN);

        // Foto yang tadi dipilih untuk nota LAIN tidak boleh terbawa ke panel berikutnya.
        // Tanpa ini, foto struk grosir A terpasang ke nota grosir B hanya karena panelnya
        // ditutup lalu nota lain dibuka — dan bukti yang menempel di nota yang salah lebih
        // buruk daripada tidak ada bukti.
        $this->bukti = null;
        $this->resetValidation('bukti');
    }

    public function tutupRincian(): void
    {
        $this->rincianId = null;
        $this->bukti = null;
        $this->resetValidation('bukti');
    }

    /* ── Bukti belanja ───────────────────────────────────────────────────── */

    /**
     * Kabar SEKARANG kalau fotonya bermasalah — tanpa membuang berkasnya.
     *
     * Sama dengan PembelianBaru::updatedBukti(): pesannya ada supaya pemilik bisa memilih
     * foto lain sebelum menekan tombol, dan aksinya tetap memeriksa ulang secara diam supaya
     * tidak ada satu pun jalur yang bisa membuat nota gagal karena berkas.
     */
    public function updatedBukti(): void
    {
        $this->validate(
            ['bukti' => SimpanBuktiBelanjaAction::aturan()],
            SimpanBuktiBelanjaAction::pesan(),
            ['bukti' => 'foto bukti'],
        );
    }

    /**
     * Membuang foto yang baru DIPILIH — bukan foto yang sudah terpasang di nota.
     *
     * Nama & perilakunya sama dengan PembelianBaru::buangBuktiPilihan() supaya satu
     * pekerjaan ("salah pilih berkas, mau memilih ulang") tidak punya dua nama di dua layar.
     * Sengaja BUKAN `$set('bukti', null)` dari Blade: itu meninggalkan pesan galat dari
     * berkas sebelumnya menggantung di layar padahal berkasnya sudah tidak ada, dan pesan
     * galat yang tidak bisa dihilangkan membuat orang mengira layarnya macet.
     *
     * TIDAK menyentuh disk dan TIDAK menyentuh nota: yang dibuang hanya unggahan sementara.
     */
    public function buangBuktiPilihan(): void
    {
        $this->bukti = null;
        $this->resetValidation('bukti');
    }

    /**
     * Memasang/mengganti foto bukti pada nota yang rinciannya sedang dibuka.
     *
     * Gerbangnya kueri() — tersaring tenant DAN outlet — jadi nota cabang lain maupun
     * merchant lain terbaca sebagai "tidak ada". rincianId datang dari klien dan boleh
     * datang dari klien: yang menentukan bukan nilainya, melainkan kueri yang membatasi
     * apa yang bisa ditemukan dengan nilai itu.
     *
     * Berlaku pada nota BELUM DATANG maupun SUDAH DATANG. Membatasinya ke nota yang sudah
     * diterima akan mematikan justru keadaan yang paling sering: pemilik membayar di depan
     * grosir, struknya dipegang, barangnya menyusul besok.
     */
    public function pasangBukti(): void
    {
        $nota = $this->rincianId === null
            ? null
            : $this->kueri()->whereKey($this->rincianId)->first();

        if ($nota === null) {
            $this->bukti = null;
            $this->toast('Nota belanja tidak ditemukan.', 'peringatan');

            return;
        }

        if ($this->bukti === null) {
            $this->toast('Pilih dulu foto kwitansi atau struknya.', 'peringatan');

            return;
        }

        if ($nota->buktiTerkunci()) {
            // Dinyatakan sendiri, bukan dibiarkan jatuh ke pesan kegagalan umum: "fotonya
            // belum terpasang" untuk nota yang memang DIKUNCI membuat pemilik mencoba lagi
            // berkali-kali dan menyimpulkan aplikasinya rusak.
            $this->bukti = null;
            $this->toast(
                'Nota '.$nota->nomor_po.' sudah dibatalkan, jadi fotonya dikunci. Yang sudah ada tetap bisa dilihat.',
                'peringatan',
            );

            return;
        }

        // Dibaca SEBELUM aksinya jalan: sesudah itu kolomnya sudah menunjuk berkas baru dan
        // pertanyaan "tadi sudah ada fotonya atau belum" tidak bisa dijawab lagi.
        $adaSebelumnya = filled($nota->bukti_path);

        $berhasil = app(SimpanBuktiBelanjaAction::class)->execute($nota, $this->bukti);

        $this->bukti = null;
        $this->resetValidation('bukti');

        $this->toast(
            match (true) {
                $berhasil && $adaSebelumnya => 'Foto bukti nota '.$nota->nomor_po.' diganti. Foto lamanya dibuang.',
                $berhasil => 'Foto bukti nota '.$nota->nomor_po.' tersimpan.',
                default => 'Fotonya belum terpasang, dan nota '.$nota->nomor_po.' tidak berubah. Coba lagi dengan foto yang lebih kecil atau kalau sinyal sudah bagus.',
            },
            $berhasil ? 'sukses' : 'peringatan',
        );
    }

    /**
     * Membuang foto bukti sebuah nota.
     *
     * Boleh: bukti yang salah foto (struk warung lain, foto layar yang kabur) lebih buruk
     * daripada tidak ada bukti. Tidak boleh untuk nota yang DIBATALKAN — lihat
     * SimpanBuktiBelanjaAction, dan aturan keras nomor 6.
     */
    public function hapusBukti(): void
    {
        $nota = $this->rincianId === null
            ? null
            : $this->kueri()->whereKey($this->rincianId)->first();

        if ($nota === null) {
            $this->toast('Nota belanja tidak ditemukan.', 'peringatan');

            return;
        }

        if ($nota->buktiTerkunci()) {
            $this->toast(
                'Nota '.$nota->nomor_po.' sudah dibatalkan, jadi fotonya dikunci — justru itu bukti barangnya dikembalikan.',
                'peringatan',
            );

            return;
        }

        $terjadi = app(SimpanBuktiBelanjaAction::class)->hapus($nota);

        $this->toast(
            $terjadi
                ? 'Foto bukti nota '.$nota->nomor_po.' dibuang. Notanya sendiri tetap tersimpan.'
                : 'Nota '.$nota->nomor_po.' memang belum ada fotonya.',
            $terjadi ? 'sukses' : 'info',
        );
    }

    /**
     * Baris nota yang sedang dibuka, berhalaman.
     *
     * Dihalamankan walau notanya biasanya pendek: nota belanja bulanan kelontong bisa
     * berisi 40 barang, dan `limit(n)` tanpa penunjuk halaman adalah pemotongan diam-diam
     * — pemiliknya membandingkan total nota dengan barisnya lalu menyimpulkan totalnya
     * salah.
     *
     * @return LengthAwarePaginator<int, PurchaseOrderItem>|Collection<int, PurchaseOrderItem>
     */
    private function barisRincian(): LengthAwarePaginator|Collection
    {
        if ($this->rincianId === null) {
            return collect();
        }

        return PurchaseOrderItem::query()
            ->where('purchase_order_id', $this->rincianId)
            ->with(['product:id,nama_produk,sku', 'rawMaterial:id,nama'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(config('nampan.per_halaman'), ['*'], self::HALAMAN_RINCIAN);
    }

    /* ── Barang datang ───────────────────────────────────────────────────── */

    /**
     * Menandai nota "barangnya sudah datang": stok masuk, harga beli master diperbarui.
     *
     * Gerbangnya kueri() yang sama dengan batalkan() — tersaring tenant DAN outlet — jadi
     * nota cabang lain maupun merchant lain terbaca sebagai "tidak ada". Outlet tempat
     * barangnya masuk TIDAK diambil dari sini sama sekali: TerimaPembelianAction membacanya
     * dari notanya (`$po->outlet_id`), dan aksinya bahkan tidak punya parameter outlet.
     *
     * Aksinya idempoten, jadi tombol yang tertekan dua kali tidak menambah stok dua kali.
     */
    public function tandaiDatang(string $id): void
    {
        $nota = $this->kueri()->whereKey($id)->first();

        if ($nota === null) {
            $this->toast('Nota belanja tidak ditemukan.', 'peringatan');

            return;
        }

        if ($nota->status === DocumentStatus::Dibatalkan) {
            // Dinyatakan sendiri, bukan dibiarkan jatuh ke pesan idempotensi di bawah:
            // "sudah ditandai datang" untuk nota yang DIBATALKAN adalah keterangan yang
            // salah, dan pemiliknya lalu mencari barangnya di catatan stok.
            $this->toast('Nota '.$nota->nomor_po.' sudah dibatalkan, jadi barangnya tidak bisa ditandai datang.', 'peringatan');

            return;
        }

        $terjadi = app(TerimaPembelianAction::class)->execute($nota, auth()->user());

        $this->toast(
            $terjadi
                ? 'Nota '.$nota->nomor_po.' ditandai datang. Stok sudah bertambah.'
                : 'Nota '.$nota->nomor_po.' memang sudah ditandai datang sebelumnya; stok tidak disentuh lagi.',
            $terjadi ? 'sukses' : 'info',
        );

        // Panel rincian yang sedang terbuka ikut memperlihatkan lencana barunya tanpa
        // pemiliknya harus menutup lalu membukanya lagi.
        $this->resetPage(self::HALAMAN_RINCIAN);
    }

    /* ── Pembatalan ──────────────────────────────────────────────────────── */

    /**
     * Membatalkan nota: stok dikembalikan, harga beli dipulihkan, notanya tetap ada.
     *
     * Aksinya idempoten, jadi tombol yang tertekan dua kali tidak mengurangi stok dua
     * kali. Pesannya dibedakan supaya pemilik tahu mana yang benar-benar baru terjadi —
     * "sudah dibatalkan sebelumnya" adalah keterangan, bukan kegagalan.
     *
     * Pesan "stok dikembalikan" HANYA untuk nota yang barangnya memang pernah masuk. Ini
     * cacat nyata yang sudah pernah ada di sini: nota yang barangnya belum datang tidak
     * pernah menambah stok, jadi mengaku mengembalikannya membuat pemilik mencari 24 pcs
     * yang tidak pernah ada di catatannya — dan pesan yang salah satu kali membuat seluruh
     * pesan berikutnya berhenti dipercaya.
     */
    public function batalkan(string $id): void
    {
        $nota = $this->kueri()->whereKey($id)->first();

        if ($nota === null) {
            // Termasuk nota milik merchant lain: global scope sudah menyaringnya, jadi
            // yang tersisa terbaca sebagai "tidak ada".
            $this->toast('Nota belanja tidak ditemukan.', 'peringatan');

            return;
        }

        // Dibaca SEBELUM aksinya jalan: sesudah itu statusnya sudah Dibatalkan dan
        // pertanyaan "apakah barangnya pernah masuk" tidak bisa dijawab lagi.
        $pernahMasuk = $nota->status->movesStock();

        $terjadi = app(BatalkanPembelianAction::class)->execute($nota, auth()->user());

        $this->toast(
            match (true) {
                $terjadi && $pernahMasuk => 'Nota '.$nota->nomor_po.' dibatalkan. Stok dikembalikan seperti sebelum nota ini dicatat.',
                $terjadi => 'Nota '.$nota->nomor_po.' dibatalkan. Barangnya belum datang, jadi tidak ada stok yang berubah.',
                default => 'Nota '.$nota->nomor_po.' memang sudah dibatalkan sebelumnya; stok tidak disentuh lagi.',
            },
            $terjadi ? 'sukses' : 'info',
        );
    }

    /* ── Daftar ──────────────────────────────────────────────────────────── */

    /** @return Builder<PurchaseOrder> */
    private function kueri()
    {
        $cari = trim($this->cari);
        $outletId = $this->outletTerpakai();

        return PurchaseOrder::query()
            ->with(['supplier:id,nama', 'outlet:id,outlet_name'])
            ->withCount('items')
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
            ->when($this->status === 'diterima', fn ($q) => $q->where('status', DocumentStatus::Diterima->value))
            ->when($this->status === 'belum', fn ($q) => $q->where('status', DocumentStatus::Dikirim->value))
            ->when($this->status === 'dibatalkan', fn ($q) => $q->where('status', DocumentStatus::Dibatalkan->value))
            ->when($cari !== '', fn ($q) => $q->where(function ($w) use ($cari) {
                $w->where('nomor_po', 'like', '%'.$cari.'%')
                    ->orWhereHas('supplier', fn ($s) => $s->where('nama', 'like', '%'.$cari.'%'));
            }))
            ->orderByDesc('tanggal')
            ->orderByDesc('created_at');
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

    /**
     * Angka kepala layar: uang yang keluar bulan ini dan berapa nota yang menghasilkannya.
     *
     * SENGAJA tidak mengikuti saringan pencarian maupun status — kalimat di kartunya
     * berbunyi "bulan ini", dan angka yang diam-diam berubah saat kata pencarian diketik
     * tidak lagi menjawab pertanyaan itu. Yang tetap diikuti hanya outlet: belanja cabang
     * lain tidak pernah dibayar dari laci cabang ini.
     *
     * "Belanja bulan ini" HANYA menghitung nota yang barangnya SUDAH DATANG. Uang untuk
     * barang yang belum ada tidak boleh berbaur dengan uang yang sudah jadi barang: yang
     * pertama masih bisa hangus atau berubah jumlahnya, yang kedua sudah ada di rak. Satu
     * angka yang mencampur keduanya tidak bisa dipakai untuk memutuskan apa pun — dan
     * pemilik yang melihatnya melompat naik pada hari ia mencatat pesanan akan menyimpulkan
     * uangnya sudah keluar. Nota yang dibatalkan juga tidak ikut, dengan alasan yang sama.
     *
     * "Menunggu datang" TIDAK dibatasi bulan ini, dan itu disengaja: nota yang barangnya
     * belum sampai sejak bulan lalu justru yang paling perlu ditanyakan ke grosirnya. Karena
     * itu umur nota TERTUA ikut dikirim — "menunggu 19 hari" adalah pertanyaan, sedangkan
     * "3 nota menunggu" cuma angka.
     *
     * `addMonthNoOverflow()`, bukan endOfMonth/subMonths mentah: batas atas dibuat
     * eksklusif supaya nota tertanggal hari terakhir bulan tetap ikut terhitung.
     *
     * @return array{belanja: float, nota: int, dibatalkan: int, menunggu: array{nilai: float, nota: int, tertua: ?Carbon, umur_hari: ?int}}
     */
    private function ringkasanBulanIni(): array
    {
        $awal = now()->startOfMonth();
        $akhir = $awal->copy()->addMonthNoOverflow();
        $outletId = $this->outletTerpakai();

        $dasar = fn () => PurchaseOrder::query()
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
            ->where('tanggal', '>=', $awal->toDateString())
            ->where('tanggal', '<', $akhir->toDateString());

        return [
            'belanja' => (float) $dasar()->where('status', DocumentStatus::Diterima->value)->sum('total'),
            'nota' => (int) $dasar()->where('status', DocumentStatus::Diterima->value)->count(),
            'dibatalkan' => (int) $dasar()->where('status', DocumentStatus::Dibatalkan->value)->count(),
            'menunggu' => $this->ringkasanMenungguDatang($outletId),
        ];
    }

    /**
     * Nota yang barangnya belum datang: nilainya, jumlahnya, dan umur yang tertua.
     *
     * Umur dihitung dari `tanggal` nota (tanggal belanjanya), bukan dari `created_at`:
     * pemilik boleh mencatat nota kemarin hari ini, dan yang ia tunggu adalah barang yang
     * dipesan pada tanggal notanya. `startOfDay()` di kedua sisi supaya "hari ini" selalu
     * 0 hari dan tidak pernah terbaca 1 hari hanya karena jamnya sudah lewat tengah hari.
     *
     * @return array{nilai: float, nota: int, tertua: ?Carbon, umur_hari: ?int}
     */
    private function ringkasanMenungguDatang(?string $outletId): array
    {
        $dasar = fn () => PurchaseOrder::query()
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
            ->where('status', DocumentStatus::Dikirim->value);

        $tertua = $dasar()->min('tanggal');
        $tertua = $tertua === null ? null : Carbon::parse($tertua)->startOfDay();

        return [
            'nilai' => (float) $dasar()->sum('total'),
            'nota' => (int) $dasar()->count(),
            'tertua' => $tertua,
            'umur_hari' => $tertua === null ? null : (int) $tertua->diffInDays(now()->startOfDay()),
        ];
    }

    public function render()
    {
        $daftar = $this->kueri()->paginate(config('nampan.per_halaman'), ['*'], self::NAMA_HALAMAN);

        return view('livewire.pages.owner.pembelian.pembelian', [
            'daftar' => $daftar,
            'notaRincian' => $this->rincianId === null
                ? null
                : $daftar->firstWhere('id', $this->rincianId) ?? PurchaseOrder::query()->find($this->rincianId),
            'barisRincian' => $this->barisRincian(),
            'ringkasan' => $this->ringkasanBulanIni(),
            'outletTersedia' => auth()->user()->scopedOutletId() === null ? $this->outletTersedia() : [],
            'outletDipakai' => $this->outletTerpakai(),
            // WAJIB terpasang di blok konfirmasi "tandai datang". Terima sebagian tidak
            // dibangun (qty_diterima selalu penuh), jadi tanpa kalimat ini pemilik yang
            // menerima 8 dari 10 mengarang jalannya sendiri.
            'catatanTerimaSebagian' => TerimaPembelianAction::CATATAN_TERIMA_SEBAGIAN,
            // Batas ukuran foto bukti, sudah berbentuk kata ("4 MB"). Sama dengan yang
            // dikirim PembelianBaru, dan dengan alasan yang sama: angka yang diketik ulang
            // di Blade akan ketinggalan saat setelannya diubah, dan keterangan batas yang
            // salah membuat orang mencoba berkali-kali dengan foto yang memang akan ditolak.
            'batasBukti' => SimpanBuktiBelanjaAction::labelBatas(),
        ]);
    }
}
