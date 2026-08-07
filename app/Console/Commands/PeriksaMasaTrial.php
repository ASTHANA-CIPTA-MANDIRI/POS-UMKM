<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Langganan\Subscription;
use App\Models\Sistem\AuditLog;
use App\Models\Tenant\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class PeriksaMasaTrial extends Command
{
    protected $signature = 'nampan:periksa-trial {--dry-run : Tampilkan yang akan diubah tanpa menyimpan}';

    protected $description = 'Menyuspend merchant yang masa trial atau masa tenggangnya sudah lewat';

    /**
     * Dijalankan terjadwal setiap hari.
     *
     * Statusnya diubah menjadi SUSPEND, bukan nonaktif dan bukan dihapus. Alur 2.3
     * dokumen bisnis: merchant yang berhenti membayar kehilangan kemampuan
     * bertransaksi, tapi datanya tetap utuh supaya ia bisa kembali kapan saja —
     * dan supaya ia masih bisa mengekspor datanya.
     */
    public function handle(TenantContext $context): int
    {
        $kering = (bool) $this->option('dry-run');

        // Perintah ini berjalan tanpa tenant di context, jadi harus lintas tenant.
        // withoutScoping dipasang eksplisit agar tetap benar kalau nanti dipanggil
        // dari dalam request yang sudah punya context.
        [$trial, $langganan] = $context->withoutScoping(fn () => [
            Tenant::where('status', TenantStatus::Trial)
                ->whereNotNull('trial_ends_at')
                ->where('trial_ends_at', '<', now())
                ->get(),
            Subscription::with('tenant')
                ->whereIn('status', [SubscriptionStatus::Aktif, SubscriptionStatus::GracePeriod])
                ->whereNotNull('tanggal_berakhir')
                ->where('tanggal_berakhir', '<', now()->toDateString())
                ->get(),
        ]);

        $jumlahTrial = 0;
        $jumlahLangganan = 0;

        foreach ($trial as $tenant) {
            $this->line("  trial habis: {$tenant->business_name} (berakhir {$tenant->trial_ends_at->toDateString()})");

            if (! $kering) {
                $tenant->update(['status' => TenantStatus::Suspend]);
                $this->catatAudit($context, $tenant, 'trial_berakhir', [
                    'trial_ends_at' => $tenant->trial_ends_at->toDateString(),
                ]);
            }

            $jumlahTrial++;
        }

        foreach ($langganan as $item) {
            $tenant = $item->tenant;

            if ($tenant === null) {
                continue;
            }

            // Masa tenggang yang belum lewat tidak disuspend — itu justru gunanya.
            if ($item->grace_period_sampai !== null && $item->grace_period_sampai->isFuture()) {
                continue;
            }

            $this->line("  langganan berakhir: {$tenant->business_name}");

            if (! $kering) {
                $item->update(['status' => SubscriptionStatus::Suspend]);
                $tenant->update(['status' => TenantStatus::Suspend]);
                $this->catatAudit($context, $tenant, 'langganan_berakhir', [
                    'subscription_id' => $item->getKey(),
                ]);
            }

            $jumlahLangganan++;
        }

        $awalan = $kering ? '[dry-run] ' : '';
        $this->info("{$awalan}Trial habis: {$jumlahTrial}. Langganan berakhir: {$jumlahLangganan}.");

        return self::SUCCESS;
    }

    private function catatAudit(TenantContext $context, Tenant $tenant, string $aksi, array $detail): void
    {
        $context->forTenant($tenant->getKey(), function () use ($tenant, $aksi, $detail) {
            AuditLog::create([
                'aksi' => $aksi,
                'entitas_terkait' => 'tenant',
                'entitas_id' => $tenant->getKey(),
                'detail_json' => $detail,
            ]);
        });
    }
}
