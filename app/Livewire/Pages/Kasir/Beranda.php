<?php

namespace App\Livewire\Pages\Kasir;

use App\Actions\Kas\BukaSesiKasAction;
use App\Actions\Kas\KoreksiModalAwalAction;
use App\Enums\CashSessionStatus;
use App\Enums\TransactionStatus;
use App\Livewire\Concerns\TerikatTenant;
use App\Models\Bill;
use App\Models\CashSession;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Beranda kasir: status shift, riwayat transaksi, dan pekerjaan yang menunggu.
 *
 * Semua angka dibatasi ke outlet kasir yang login. Kasir tidak boleh melihat data
 * outlet lain (bagian 3.3 dokumen), dan pembatasan itu diambil dari record user di
 * database — bukan dari pilihan di layar.
 */
#[Layout('layouts.kasir', ['penuh' => true])]
class Beranda extends Component
{
    use TerikatTenant;

    /**
     * Rentang riwayat: 'shift' (sejak laci dibuka) atau 'hari' (sejak tengah malam).
     *
     * Ditaruh di URL supaya kasir yang memuat ulang halaman tidak diam-diam kembali
     * ke rentang lain, lalu menyimpulkan transaksinya hilang.
     */
    #[Url(as: 'rentang')]
    public string $rentang = 'shift';

    /**
     * Modal awal untuk membuka laci — SENGAJA tanpa nilai bawaan.
     *
     * Angka ini adalah pernyataan kasir tentang uang yang ia hitung sendiri di laci.
     * Kolom yang sudah terisi mengundang kasir menekan "Buka kasir" tanpa menghitung,
     * dan begitu itu terjadi selisih di akhir shift tidak lagi berarti apa-apa —
     * pembandingnya bukan kenyataan, melainkan angka bawaan aplikasi.
     *
     * null berarti belum diisi. Tidak dipakai 0 sebagai penanda, karena laci yang
     * benar-benar kosong adalah keadaan yang sah dan harus bisa dicatat.
     */
    public ?float $modalAwal = null;

    public ?string $galat = null;

    /**
     * Membuka laci kas dari beranda.
     *
     * Sebelumnya satu-satunya jalan adalah gerbang di layar transaksi, dan itu tidak
     * pernah terlihat oleh kasir yang lacinya sudah dibuka orang lain — ia tidak punya
     * cara menemukan di mana angka itu dicatat. Beranda adalah tempat shift dimulai,
     * jadi di sinilah tempatnya.
     */
    public function bukaSesi(BukaSesiKasAction $buka): void
    {
        if ($this->modalAwal === null) {
            $this->galat = 'Hitung uang di laci dulu, lalu isi jumlahnya.';

            return;
        }

        try {
            $buka->execute(auth()->user(), $this->modalAwal);
            $this->galat = null;
        } catch (RuntimeException $e) {
            $this->galat = $e->getMessage();
        }
    }

    /* ── Koreksi modal awal ──────────────────────────────────────────────── */

    public bool $panelKoreksi = false;

    public ?float $modalKoreksi = null;

    public string $alasanKoreksi = '';

    public ?string $galatKoreksi = null;

    public function bukaKoreksi(): void
    {
        $this->panelKoreksi = true;
        $this->galatKoreksi = null;
        $this->modalKoreksi = null;
        $this->alasanKoreksi = '';
    }

    public function tutupKoreksi(): void
    {
        $this->panelKoreksi = false;
        $this->galatKoreksi = null;
    }

    /**
     * Mengoreksi modal awal shift yang sedang berjalan.
     *
     * Nilai lamanya tidak hilang: aksinya menulis catatan audit berisi angka lama,
     * angka baru, alasan, dan siapa yang mengubah. Lihat KoreksiModalAwalAction.
     */
    public function simpanKoreksi(KoreksiModalAwalAction $koreksi, BukaSesiKasAction $buka): void
    {
        $sesi = $buka->sesiBerjalan(auth()->user());

        if ($sesi === null) {
            $this->galatKoreksi = 'Tidak ada shift yang berjalan.';

            return;
        }

        if ($this->modalKoreksi === null) {
            $this->galatKoreksi = 'Isi dulu jumlah yang benar.';

            return;
        }

        try {
            $koreksi->execute($sesi, $this->modalKoreksi, $this->alasanKoreksi, auth()->user());
            $this->panelKoreksi = false;
            $this->galatKoreksi = null;
        } catch (RuntimeException $e) {
            $this->galatKoreksi = $e->getMessage();
        }
    }

    public function pilihRentang(string $rentang): void
    {
        $this->rentang = in_array($rentang, ['shift', 'hari'], true) ? $rentang : 'shift';
    }

    public function render()
    {
        $user = auth()->user();
        $outletId = $user->outlet_id;
        $sesi = $this->sesiBerjalan($user->getKey(), $outletId);

        // Tanpa sesi terbuka, rentang "shift" tidak punya titik mulai — jatuh ke hari ini.
        $rentang = $sesi === null ? 'hari' : $this->rentang;

        $riwayat = $this->riwayat($user->getKey(), $sesi, $rentang);

        return view('livewire.pages.kasir.beranda', [
            'sesi' => $sesi,
            'rentangAktif' => $rentang,
            'riwayat' => $riwayat,
            'ringkas' => $this->ringkas($riwayat),
            'kasSistem' => $sesi ? $sesi->hitungKasSistem() : 0.0,
            'riwayatKoreksi' => $sesi ? app(KoreksiModalAwalAction::class)->riwayat($sesi) : collect(),
            'billTerbuka' => Bill::terbuka()
                ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
                ->latest('dibuka_pada')
                ->limit(6)
                ->get(),
            'jumlahBillTerbuka' => Bill::terbuka()
                ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
                ->count(),
        ]);
    }

    /**
     * Sesi kas milik kasir ini yang masih terbuka. Dicari per kasir, bukan per
     * outlet: dua kasir bisa bergantian di outlet yang sama dan masing-masing
     * bertanggung jawab atas laci kasnya sendiri.
     */
    private function sesiBerjalan(string $userId, ?string $outletId): ?CashSession
    {
        return CashSession::where('staff_id', $userId)
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->where('status', CashSessionStatus::Terbuka)
            ->latest('dibuka_pada')
            ->first();
    }

    /**
     * Riwayat transaksi kasir ini.
     *
     * payments dan hitungan item dimuat sekaligus: tiap baris menampilkan metode
     * bayar dan jumlah item, dan tanpa eager load setiap baris menembak query sendiri.
     *
     * @return Collection<int, Transaction>
     */
    private function riwayat(string $userId, ?CashSession $sesi, string $rentang): Collection
    {
        return Transaction::query()
            ->with('payments')
            ->withCount('items')
            /*
             * Draft DIKELUARKAN. Transaksi draft adalah pesanan bill yang belum
             * dibayar — ia belum menjadi transaksi, dan tempatnya di kartu "Bill
             * terbuka", bukan di riwayat. Membiarkannya di sini membuat omzet di kartu
             * ringkas menghitung uang yang belum diterima siapa pun.
             */
            ->whereNot('status', TransactionStatus::Draft)
            ->where('staff_id', $userId)
            /*
             * "Shift ini" dibatasi dengan WAKTU, bukan dengan id sesi kas.
             *
             * Tabel transactions tidak menyimpan cash_session_id; hubungannya ada di
             * cash_movements. Tapi pergerakan kas hanya lahir dari pembayaran TUNAI,
             * jadi menyaring lewat sana akan membuang transaksi QRIS dari daftar shift.
             * Batas waktu "sejak laci dibuka" persis sama dengan arti labelnya dan
             * berlaku untuk semua metode bayar.
             */
            ->when(
                $rentang === 'shift' && $sesi !== null,
                fn ($q) => $q->where('waktu_transaksi', '>=', $sesi->dibuka_pada),
                fn ($q) => $q->whereDate('waktu_transaksi', today()),
            )
            ->latest('waktu_transaksi')
            ->limit(40)
            ->get();
    }

    /**
     * Ringkasan dihitung dari riwayat yang SUDAH diambil, bukan lewat query agregat
     * tersendiri. Dengan begitu angka di kartu tidak mungkin bercerita lain dari
     * daftar di bawahnya — sumbernya sama.
     *
     * @param  Collection<int, Transaction>  $riwayat
     * @return array<string, float|int>
     */
    private function ringkas(Collection $riwayat): array
    {
        // Void & refund tidak menambah omzet; keduanya tetap tampil di daftar supaya
        // kasir bisa melihat bahwa transaksinya memang ada dan dibatalkan.
        $dibatalkan = [TransactionStatus::Void->value, TransactionStatus::Refund->value];
        $dihitung = $riwayat->reject(fn (Transaction $t) => in_array($t->status->value, $dibatalkan, true));

        return [
            'jumlah' => $dihitung->count(),
            'omzet' => (float) $dihitung->sum('total'),
            'tunai' => (float) $dihitung->sum(
                fn (Transaction $t) => $t->payments
                    ->filter(fn ($p) => $p->metode->value === 'cash')
                    ->sum('jumlah'),
            ),
            'belum_lunas' => (float) $dihitung
                ->filter(fn (Transaction $t) => $t->status->value === 'belum_lunas')
                ->sum('total'),
        ];
    }
}
