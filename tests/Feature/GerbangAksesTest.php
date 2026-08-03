<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Menguji gerbang peran dan gerbang status langganan.
 */
class GerbangAksesTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    public function test_pengunjung_belum_login_diarahkan_ke_halaman_masuk(): void
    {
        $this->get(route('owner.dasbor'))->assertRedirect(route('masuk'));
        $this->get(route('admin.dasbor'))->assertRedirect(route('masuk'));
        $this->get(route('kasir.beranda'))->assertRedirect(route('masuk'));
    }

    /**
     * Peran yang salah dialihkan ke berandanya sendiri, bukan diberi 403 telanjang —
     * kasir yang salah membuka URL back office tidak boleh terjebak halaman mati.
     */
    public function test_kasir_tidak_bisa_masuk_back_office(): void
    {
        $tenant = $this->buatTenant();
        $outlet = $this->buatOutlet($tenant);

        $kasir = $this->buatUser($tenant, UserRole::Kasir, [
            'username' => 'kasir1',
            'pin_hash' => '123456',
            'outlet_id' => $outlet->getKey(),
        ]);

        $this->actingAs($kasir)
            ->get(route('owner.dasbor'))
            ->assertRedirect(route('kasir.beranda'));
    }

    public function test_owner_tidak_bisa_masuk_area_platform(): void
    {
        $tenant = $this->buatTenant();
        $owner = $this->buatUser($tenant, UserRole::Owner, [
            'email' => 'o@u.test',
            'password' => 'rahasia123',
        ]);

        $this->actingAs($owner)
            ->get(route('admin.dasbor'))
            ->assertRedirect(route('owner.dasbor'));
    }

    public function test_super_admin_tidak_terpengaruh_gerbang_tenant(): void
    {
        $admin = $this->buatSuperAdmin();

        $this->actingAs($admin)
            ->get(route('admin.dasbor'))
            ->assertOk();
    }

    /**
     * Alur 2.3 dokumen: saat suspend, merchant tidak bisa transaksi tapi datanya
     * tetap ada. Yang diblokir hanya jalur operasional.
     */
    public function test_merchant_suspend_dialihkan_ke_halaman_langganan(): void
    {
        $tenant = $this->buatTenant('Warung Suspend', TenantStatus::Suspend);
        $owner = $this->buatUser($tenant, UserRole::Owner, [
            'email' => 'suspend@u.test',
            'password' => 'rahasia123',
        ]);

        $this->actingAs($owner)
            ->get(route('owner.dasbor'))
            ->assertRedirect(route('owner.langganan'));
    }

    /**
     * Halaman langganan WAJIB tetap terbuka saat suspend. Kalau ikut diblokir,
     * merchant tidak punya jalan untuk membayar dan keluar dari suspend.
     */
    public function test_halaman_langganan_tetap_terbuka_saat_suspend(): void
    {
        $tenant = $this->buatTenant('Warung Suspend', TenantStatus::Suspend);
        $owner = $this->buatUser($tenant, UserRole::Owner, [
            'email' => 'suspend2@u.test',
            'password' => 'rahasia123',
        ]);

        $this->actingAs($owner)
            ->get(route('owner.langganan'))
            ->assertOk()
            ->assertSee('Paket &amp; tagihan', false);
    }

    public function test_kasir_dari_merchant_suspend_tidak_bisa_transaksi(): void
    {
        $tenant = $this->buatTenant('Warung Suspend', TenantStatus::Suspend);
        $outlet = $this->buatOutlet($tenant);

        $kasir = $this->buatUser($tenant, UserRole::Kasir, [
            'username' => 'kasir2',
            'pin_hash' => '123456',
            'outlet_id' => $outlet->getKey(),
        ]);

        $this->actingAs($kasir)
            ->get(route('kasir.beranda'))
            ->assertRedirect(route('owner.langganan'));
    }

    public function test_merchant_trial_masih_boleh_bertransaksi(): void
    {
        $tenant = $this->buatTenant('Warung Trial', TenantStatus::Trial, now()->addDays(5)->toDateTimeString());
        $outlet = $this->buatOutlet($tenant);

        $kasir = $this->buatUser($tenant, UserRole::Kasir, [
            'username' => 'kasir3',
            'pin_hash' => '123456',
            'outlet_id' => $outlet->getKey(),
        ]);

        $this->actingAs($kasir)
            ->get(route('kasir.beranda'))
            ->assertOk();
    }
}
