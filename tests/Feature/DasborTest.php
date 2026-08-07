<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Enums\TransactionMode;
use App\Enums\TransactionStatus;
use App\Enums\UserRole;
use App\Livewire\Pages\Admin\Dasbor\Dasbor as DasborAdmin;
use App\Livewire\Pages\Owner\Dasbor\Dasbor as DasborOwner;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Menguji bahwa angka di dasbor benar-benar terbatas pada tenant (dan outlet) yang
 * berhak melihatnya. Kebocoran angka antar merchant adalah kegagalan paling fatal
 * di produk multi-tenant — omzet tetangga tidak boleh terlihat.
 */
class DasborTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private function buatTransaksi(Tenant $tenant, Outlet $outlet, float $total, string $nomor): Transaction
    {
        return $this->konteks()->forTenant($tenant->getKey(), fn () => Transaction::create([
            'outlet_id' => $outlet->getKey(),
            'nomor_transaksi' => $nomor,
            'mode' => TransactionMode::Langsung,
            'subtotal' => $total,
            'total' => $total,
            'status' => TransactionStatus::Lunas,
            'waktu_transaksi' => now(),
        ]));
    }

    public function test_omzet_hanya_menghitung_tenant_sendiri(): void
    {
        $warteg = $this->buatTenant('Warteg');
        $outletWarteg = $this->buatOutlet($warteg, 'Warteg Pusat');
        $this->buatTransaksi($warteg, $outletWarteg, 50000, 'TRX-W-1');

        $kelontong = $this->buatTenant('Kelontong');
        $outletKelontong = $this->buatOutlet($kelontong, 'Kelontong Pusat');
        $this->buatTransaksi($kelontong, $outletKelontong, 900000, 'TRX-K-1');

        $owner = $this->buatUser($warteg, UserRole::Owner, [
            'email' => 'w@u.test',
            'password' => 'rahasia123',
        ]);

        Livewire::actingAs($owner)
            ->test(DasborOwner::class)
            ->assertViewHas('omzetHariIni', 50000.0)
            ->assertViewHas('jumlahTransaksi', 1);
    }

    /** Manager Outlet dikunci ke satu outlet, jadi angkanya ikut dipersempit. */
    public function test_manager_outlet_hanya_melihat_outletnya(): void
    {
        $tenant = $this->buatTenant('Dua Outlet');
        $outletA = $this->buatOutlet($tenant, 'Outlet A');
        $outletB = $this->buatOutlet($tenant, 'Outlet B');

        $this->buatTransaksi($tenant, $outletA, 30000, 'TRX-A-1');
        $this->buatTransaksi($tenant, $outletB, 70000, 'TRX-B-1');

        $manager = $this->buatUser($tenant, UserRole::ManagerOutlet, [
            'email' => 'm@u.test',
            'password' => 'rahasia123',
            'outlet_id' => $outletA->getKey(),
        ]);

        Livewire::actingAs($manager)
            ->test(DasborOwner::class)
            ->assertViewHas('omzetHariIni', 30000.0);
    }

    /** Owner melihat gabungan seluruh outletnya. */
    public function test_owner_melihat_gabungan_semua_outlet(): void
    {
        $tenant = $this->buatTenant('Dua Outlet');
        $outletA = $this->buatOutlet($tenant, 'Outlet A');
        $outletB = $this->buatOutlet($tenant, 'Outlet B');

        $this->buatTransaksi($tenant, $outletA, 30000, 'TRX-A-1');
        $this->buatTransaksi($tenant, $outletB, 70000, 'TRX-B-1');

        $owner = $this->buatUser($tenant, UserRole::Owner, [
            'email' => 'o2@u.test',
            'password' => 'rahasia123',
        ]);

        Livewire::actingAs($owner)
            ->test(DasborOwner::class)
            ->assertViewHas('omzetHariIni', 100000.0);
    }

    /** Transaksi draft (bill masih terbuka) belum boleh dihitung sebagai omzet. */
    public function test_transaksi_draft_tidak_dihitung_sebagai_omzet(): void
    {
        $tenant = $this->buatTenant('Warteg');
        $outlet = $this->buatOutlet($tenant, 'Pusat');

        $this->buatTransaksi($tenant, $outlet, 40000, 'TRX-1');

        $this->konteks()->forTenant($tenant->getKey(), fn () => Transaction::create([
            'outlet_id' => $outlet->getKey(),
            'nomor_transaksi' => 'TRX-DRAFT',
            'mode' => TransactionMode::OpenBill,
            'subtotal' => 999000,
            'total' => 999000,
            'status' => TransactionStatus::Draft,
            'waktu_transaksi' => now(),
        ]));

        $owner = $this->buatUser($tenant, UserRole::Owner, [
            'email' => 'o3@u.test',
            'password' => 'rahasia123',
        ]);

        Livewire::actingAs($owner)
            ->test(DasborOwner::class)
            ->assertViewHas('omzetHariIni', 40000.0);
    }

    /** Dasbor platform harus melihat SEMUA merchant, bukan hanya satu. */
    public function test_dasbor_platform_melihat_seluruh_merchant(): void
    {
        $this->buatTenant('Warteg', TenantStatus::Aktif);
        $this->buatTenant('Kelontong', TenantStatus::Aktif);
        $this->buatTenant('Depot', TenantStatus::Trial, now()->addDays(3)->toDateTimeString());

        $admin = $this->buatSuperAdmin();

        Livewire::actingAs($admin)
            ->test(DasborAdmin::class)
            ->assertViewHas('totalMerchant', 3)
            ->assertViewHas('perStatus', fn (array $perStatus) => $perStatus['aktif'] === 2 && $perStatus['trial'] === 1);
    }
}
