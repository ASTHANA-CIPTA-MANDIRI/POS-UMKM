<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Menguji perintah terjadwal yang menegakkan batas masa trial & langganan.
 */
class MasaTrialTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    public function test_trial_yang_sudah_lewat_disuspend(): void
    {
        $habis = $this->buatTenant('Sudah Habis', TenantStatus::Trial, now()->subDay()->toDateTimeString());
        $masihJalan = $this->buatTenant('Masih Jalan', TenantStatus::Trial, now()->addDays(4)->toDateTimeString());

        $this->artisan('nampan:periksa-trial')->assertSuccessful();

        $this->assertSame(TenantStatus::Suspend, $habis->fresh()->status);
        $this->assertSame(TenantStatus::Trial, $masihJalan->fresh()->status, 'Trial yang masih berlaku tidak boleh disuspend.');
    }

    /** Status merchant diubah, bukan datanya dihapus. */
    public function test_data_merchant_tetap_utuh_setelah_disuspend(): void
    {
        $tenant = $this->buatTenant('Habis', TenantStatus::Trial, now()->subDay()->toDateTimeString());
        $outlet = $this->buatOutlet($tenant);

        $this->artisan('nampan:periksa-trial')->assertSuccessful();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->getKey()]);
        $this->assertDatabaseHas('outlets', ['id' => $outlet->getKey()]);
    }

    public function test_penyuspenan_dicatat_di_log_audit(): void
    {
        $tenant = $this->buatTenant('Habis', TenantStatus::Trial, now()->subDay()->toDateTimeString());

        $this->artisan('nampan:periksa-trial')->assertSuccessful();

        $log = AuditLog::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->first();

        $this->assertNotNull($log);
        $this->assertSame('trial_berakhir', $log->aksi);
    }

    public function test_dry_run_tidak_mengubah_apa_pun(): void
    {
        $tenant = $this->buatTenant('Habis', TenantStatus::Trial, now()->subDay()->toDateTimeString());

        $this->artisan('nampan:periksa-trial', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(TenantStatus::Trial, $tenant->fresh()->status);
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_langganan_kedaluwarsa_menyuspend_merchant(): void
    {
        $plan = Plan::create([
            'nama_paket' => 'Pro',
            'slug' => 'pro-uji',
            'harga_bulanan' => 119000,
        ]);

        $tenant = $this->buatTenant('Langganan Habis', TenantStatus::Aktif);

        $langganan = $this->konteks()->forTenant($tenant->getKey(), fn () => Subscription::create([
            'plan_id' => $plan->getKey(),
            'tanggal_mulai' => now()->subMonths(2)->toDateString(),
            'tanggal_berakhir' => now()->subDay()->toDateString(),
            'status' => SubscriptionStatus::Aktif,
        ]));

        $this->artisan('nampan:periksa-trial')->assertSuccessful();

        $this->assertSame(TenantStatus::Suspend, $tenant->fresh()->status);
        $this->assertSame(SubscriptionStatus::Suspend, $langganan->fresh()->status);
    }

    /** Masa tenggang yang belum lewat justru gunanya menunda suspend. */
    public function test_masa_tenggang_yang_belum_lewat_tidak_disuspend(): void
    {
        $plan = Plan::create([
            'nama_paket' => 'Pro',
            'slug' => 'pro-uji-2',
            'harga_bulanan' => 119000,
        ]);

        $tenant = $this->buatTenant('Masih Tenggang', TenantStatus::Aktif);

        $this->konteks()->forTenant($tenant->getKey(), fn () => Subscription::create([
            'plan_id' => $plan->getKey(),
            'tanggal_mulai' => now()->subMonths(2)->toDateString(),
            'tanggal_berakhir' => now()->subDays(3)->toDateString(),
            'grace_period_sampai' => now()->addDays(4)->toDateString(),
            'status' => SubscriptionStatus::GracePeriod,
        ]));

        $this->artisan('nampan:periksa-trial')->assertSuccessful();

        $this->assertSame(TenantStatus::Aktif, $tenant->fresh()->status);
    }

    public function test_tenant_tanpa_tanggal_trial_tidak_tersentuh(): void
    {
        $tenant = Tenant::where('business_name', 'x')->first();
        $this->assertNull($tenant);

        $aktif = $this->buatTenant('Tanpa Trial', TenantStatus::Aktif);

        $this->artisan('nampan:periksa-trial')->assertSuccessful();

        $this->assertSame(TenantStatus::Aktif, $aktif->fresh()->status);
    }
}
