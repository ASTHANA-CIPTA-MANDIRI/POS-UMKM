<?php

namespace App\Livewire\Pages\Owner;

use App\Enums\CashSessionStatus;
use App\Enums\CreditStatus;
use App\Livewire\Concerns\TerikatTenant;
use App\Models\Bill;
use App\Models\CashSession;
use App\Models\CreditLedger;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Dasbor merchant. Semua angka di sini otomatis terbatas pada tenant yang login
 * karena global scope TenantScope; tidak ada filter tenant_id manual di query.
 *
 * Manager Outlet dan Kasir dikunci ke satu outlet, jadi angkanya ikut dipersempit
 * lewat User::scopedOutletId(). Owner dan Regional Manager melihat seluruh outlet.
 */
#[Layout('layouts.aplikasi')]
class Dasbor extends Component
{
    use TerikatTenant;

    public function render()
    {
        $outletId = auth()->user()->scopedOutletId();

        return view('livewire.pages.owner.dasbor.dasbor', [
            'omzetHariIni' => $this->omzetHariIni($outletId),
            'jumlahTransaksi' => $this->jumlahTransaksiHariIni($outletId),
            'billTerbuka' => $this->billTerbuka($outletId),
            'sisaKasbon' => $this->sisaKasbon(),
            'stokMenipis' => $this->stokMenipis($outletId),
            'sesiTerbuka' => $this->sesiKasTerbuka($outletId),
            'tujuhHari' => $this->omzetTujuhHari($outletId),
            'terlaris' => $this->produkTerlaris($outletId),
        ]);
    }

    private function omzetHariIni(?string $outletId): float
    {
        return (float) Transaction::omzet()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->whereDate('waktu_transaksi', today())
            ->sum('total');
    }

    private function jumlahTransaksiHariIni(?string $outletId): int
    {
        return Transaction::omzet()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->whereDate('waktu_transaksi', today())
            ->count();
    }

    private function billTerbuka(?string $outletId): int
    {
        return Bill::terbuka()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->count();
    }

    /** Sisa piutang = utang dikurangi yang sudah dibayar, bukan total utang. */
    private function sisaKasbon(): float
    {
        return (float) CreditLedger::where('status', CreditStatus::BelumLunas)
            ->sum(DB::raw('jumlah_utang - jumlah_dibayar'));
    }

    /**
     * Hanya baris yang punya ambang minimum yang dihitung. Tanpa filter itu, semua
     * item bersaldo nol ikut terhitung "menipis" dan angkanya jadi tidak berarti.
     *
     * Aturannya TIDAK ditulis ulang di sini: Stock::scopeMenipis() adalah satu-satunya
     * definisinya, dan saringan "menipis" di daftar produk memakai definisi yang sama.
     * Dua salinan aturan yang sama berarti kartu dasbor dan daftar produk cepat atau
     * lambat menyebut dua angka berbeda untuk satu kenyataan.
     */
    private function stokMenipis(?string $outletId): int
    {
        return Stock::menipis()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->count();
    }

    private function sesiKasTerbuka(?string $outletId): int
    {
        return CashSession::where('status', CashSessionStatus::Terbuka)
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->count();
    }

    /**
     * Omzet tujuh hari terakhir. Hasil query diindeks per tanggal lalu dipetakan ke
     * rentang tanggal penuh, supaya hari tanpa transaksi tetap muncul sebagai nol —
     * kalau tidak, grafiknya menyesatkan karena hari kosong menghilang.
     *
     * @return array<int, array{tanggal: Carbon, total: float}>
     */
    private function omzetTujuhHari(?string $outletId): array
    {
        $mulai = today()->subDays(6);

        $perTanggal = Transaction::omzet()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->whereDate('waktu_transaksi', '>=', $mulai)
            ->selectRaw('DATE(waktu_transaksi) as tgl, SUM(total) as jumlah')
            ->groupBy('tgl')
            ->pluck('jumlah', 'tgl');

        $hasil = [];

        for ($i = 0; $i < 7; $i++) {
            $tanggal = $mulai->copy()->addDays($i);

            $hasil[] = [
                'tanggal' => $tanggal,
                'total' => (float) ($perTanggal[$tanggal->toDateString()] ?? 0),
            ];
        }

        return $hasil;
    }

    /**
     * Lima produk terlaris tujuh hari terakhir, memakai nama snapshot di baris
     * transaksi supaya produk yang sudah dihapus tetap terhitung.
     */
    private function produkTerlaris(?string $outletId): array
    {
        return TransactionItem::query()
            ->select('transaction_items.nama_produk')
            ->selectRaw('SUM(transaction_items.qty) as total_qty')
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->whereIn('transactions.status', ['lunas', 'belum_lunas'])
            ->whereNull('transactions.deleted_at')
            ->when($outletId, fn ($q) => $q->where('transactions.outlet_id', $outletId))
            ->whereDate('transactions.waktu_transaksi', '>=', today()->subDays(6))
            ->groupBy('transaction_items.nama_produk')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get()
            ->map(fn ($baris) => [
                'nama' => $baris->nama_produk,
                'qty' => (float) $baris->total_qty,
            ])
            ->all();
    }
}
