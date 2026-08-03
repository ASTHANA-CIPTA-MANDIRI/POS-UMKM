<?php

namespace App\Livewire\Pages\Owner;

use App\Livewire\Concerns\TerikatTenant;
use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Laporan penjualan untuk owner.
 *
 * Semua angka di sini memakai scope omzet() — hanya transaksi lunas dan belum lunas
 * yang dihitung. Draft (pesanan bill yang belum dibayar) dan void/refund tidak masuk,
 * karena keduanya bukan uang yang diterima.
 */
#[Layout('layouts.aplikasi')]
class Laporan extends Component
{
    use TerikatTenant;

    /** Rentang: 'hari', '7hari', '30hari', 'bulan'. Di URL supaya bisa dibagikan. */
    #[Url(as: 'rentang')]
    public string $rentang = '7hari';

    #[Url(as: 'outlet')]
    public string $outletId = '';

    public function pilihRentang(string $rentang): void
    {
        $this->rentang = array_key_exists($rentang, $this->pilihanRentang()) ? $rentang : '7hari';
    }

    /** @return array<string, string> */
    public function pilihanRentang(): array
    {
        return [
            'hari' => 'Hari ini',
            '7hari' => '7 hari',
            '30hari' => '30 hari',
            'bulan' => 'Bulan ini',
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function batas(): array
    {
        $akhir = now()->endOfDay();

        return match ($this->rentang) {
            'hari' => [now()->startOfDay(), $akhir],
            '30hari' => [now()->subDays(29)->startOfDay(), $akhir],
            // startOfMonth aman dari luapan tanggal, tidak seperti subMonths().
            'bulan' => [now()->startOfMonth(), $akhir],
            default => [now()->subDays(6)->startOfDay(), $akhir],
        };
    }

    public function render()
    {
        [$mulai, $selesai] = $this->batas();

        $dasar = fn () => Transaction::omzet()
            ->whereBetween('waktu_transaksi', [$mulai, $selesai])
            ->when($this->outletId !== '', fn ($q) => $q->where('outlet_id', $this->outletId));

        $jumlah = (clone $dasar())->count();
        $omzet = (float) (clone $dasar())->sum('total');

        return view('livewire.pages.owner.laporan', [
            'mulai' => $mulai,
            'selesai' => $selesai,
            'pilihanRentang' => $this->pilihanRentang(),
            'outlet' => Outlet::orderBy('outlet_name')->get(['id', 'outlet_name']),
            'ringkas' => [
                'jumlah' => $jumlah,
                'omzet' => $omzet,
                // Rata-rata per transaksi menjawab "apakah pembeli belanja lebih banyak",
                // pertanyaan yang tidak terjawab oleh omzet total saja.
                'rata' => $jumlah > 0 ? $omzet / $jumlah : 0.0,
                'belum_lunas' => (float) (clone $dasar())->where('status', 'belum_lunas')->sum('total'),
            ],
            'perHari' => $this->perHari($mulai, $selesai),
            'terlaris' => $this->terlaris($mulai, $selesai),
            'perMetode' => $this->perMetode($mulai, $selesai),
        ]);
    }

    /**
     * Omzet per hari untuk grafik batang.
     *
     * Hari tanpa transaksi tetap dikeluarkan dengan nilai 0 — kalau hanya hari
     * berisi yang ditampilkan, grafiknya menyembunyikan hari sepi, dan itu justru
     * informasi yang dicari owner.
     *
     * @return array<int, array{tanggal: Carbon, total: float}>
     */
    private function perHari(Carbon $mulai, Carbon $selesai): array
    {
        $sumber = Transaction::omzet()
            ->whereBetween('waktu_transaksi', [$mulai, $selesai])
            ->when($this->outletId !== '', fn ($q) => $q->where('outlet_id', $this->outletId))
            ->selectRaw('date(waktu_transaksi) as tgl, sum(total) as jumlah')
            ->groupBy('tgl')
            ->pluck('jumlah', 'tgl');

        $hasil = [];

        for ($tanggal = $mulai->copy(); $tanggal->lte($selesai); $tanggal->addDay()) {
            $kunci = $tanggal->toDateString();
            $hasil[] = [
                'tanggal' => $tanggal->copy(),
                'total' => (float) ($sumber[$kunci] ?? 0),
            ];
        }

        return $hasil;
    }

    /** @return Collection<int, object> */
    private function terlaris(Carbon $mulai, Carbon $selesai)
    {
        return TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->whereIn('transactions.status', ['lunas', 'belum_lunas'])
            ->whereBetween('transactions.waktu_transaksi', [$mulai, $selesai])
            ->when($this->outletId !== '', fn ($q) => $q->where('transactions.outlet_id', $this->outletId))
            ->groupBy('transaction_items.nama_produk')
            ->select('transaction_items.nama_produk')
            ->selectRaw('sum(transaction_items.qty) as qty, sum(transaction_items.subtotal) as omzet')
            ->orderByDesc(DB::raw('sum(transaction_items.subtotal)'))
            ->limit(8)
            ->get();
    }

    /** @return Collection<int, object> */
    private function perMetode(Carbon $mulai, Carbon $selesai)
    {
        return TransactionPayment::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_payments.transaction_id')
            ->whereIn('transactions.status', ['lunas', 'belum_lunas'])
            ->whereBetween('transactions.waktu_transaksi', [$mulai, $selesai])
            ->when($this->outletId !== '', fn ($q) => $q->where('transactions.outlet_id', $this->outletId))
            ->groupBy('transaction_payments.metode')
            ->select('transaction_payments.metode')
            ->selectRaw('sum(transaction_payments.jumlah) as jumlah, count(*) as banyak')
            ->orderByDesc(DB::raw('sum(transaction_payments.jumlah)'))
            ->get();
    }
}
