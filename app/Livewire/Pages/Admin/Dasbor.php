<?php

namespace App\Livewire\Pages\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\TenantContext;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Dasbor pengelola platform.
 *
 * Semua query dibungkus withoutScoping. Saat ini Super Admin tidak punya tenant di
 * context sehingga global scope memang tidak aktif — tapi begitu fitur impersonate
 * merchant ditambahkan, context akan terisi dan angka platform ini akan menyusut
 * tanpa peringatan. Membungkusnya sekarang mencegah bug itu terjadi nanti.
 */
#[Layout('layouts.aplikasi')]
class Dasbor extends Component
{
    public function render()
    {
        return view('livewire.pages.admin.dasbor', app(TenantContext::class)->withoutScoping(fn () => [
            'perStatus' => $this->merchantPerStatus(),
            'totalMerchant' => Tenant::count(),
            'mrr' => $this->pendapatanBulanan(),
            'trialSegeraHabis' => $this->trialSegeraHabis(),
            'tagihanTertunggak' => $this->tagihanTertunggak(),
            'merchantBaru' => $this->merchantBaru(),
        ]));
    }

    /** @return array<string, int> */
    private function merchantPerStatus(): array
    {
        $hitungan = Tenant::selectRaw('status, COUNT(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');

        $hasil = [];

        foreach (TenantStatus::cases() as $status) {
            $hasil[$status->value] = (int) ($hitungan[$status->value] ?? 0);
        }

        return $hasil;
    }

    /**
     * Pendapatan berulang bulanan: harga paket dari langganan yang masih ditagih.
     * Yang memakai bundling perangkat dihitung memakai harga bundling, bukan harga
     * software saja — kalau tidak, MRR-nya dilaporkan lebih kecil dari kenyataan.
     */
    private function pendapatanBulanan(): float
    {
        return Subscription::with('plan')
            ->whereIn('status', [SubscriptionStatus::Aktif, SubscriptionStatus::GracePeriod])
            ->get()
            ->sum(function (Subscription $langganan) {
                $plan = $langganan->plan;

                if ($plan === null) {
                    return 0;
                }

                return (float) ($langganan->device_bundle && $plan->harga_bulanan_device !== null
                    ? $plan->harga_bulanan_device
                    : $plan->harga_bulanan);
            });
    }

    /** Merchant trial yang berakhir dalam tujuh hari — target follow-up penjualan. */
    private function trialSegeraHabis()
    {
        return Tenant::where('status', TenantStatus::Trial)
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [now(), now()->addDays(7)])
            ->orderBy('trial_ends_at')
            ->limit(6)
            ->get();
    }

    /** @return array{jumlah: int, nilai: float} */
    private function tagihanTertunggak(): array
    {
        $query = Invoice::whereIn('status', [InvoiceStatus::BelumBayar, InvoiceStatus::Telat]);

        return [
            'jumlah' => (clone $query)->count(),
            'nilai' => (float) $query->sum('jumlah_tagihan'),
        ];
    }

    private function merchantBaru()
    {
        return Tenant::latest('created_at')->limit(5)->get();
    }
}
