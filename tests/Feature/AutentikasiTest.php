<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Pages\Auth\Masuk\Masuk;
use App\Livewire\Pages\Auth\Masuk\MasukKasir;
use App\Models\Tenant\Device;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

class AutentikasiTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outletA;

    private Outlet $outletB;

    private Device $perangkatA;

    private Device $perangkatB;

    private User $owner;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant();
        $this->outletA = $this->buatOutlet($this->tenant, 'Outlet A');
        $this->outletB = $this->buatOutlet($this->tenant, 'Outlet B');
        $this->perangkatA = $this->buatPerangkat($this->tenant, $this->outletA, 'TAB-A');
        $this->perangkatB = $this->buatPerangkat($this->tenant, $this->outletB, 'TAB-B');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Bu Owner',
            'email' => 'owner@warung.test',
            'password' => 'rahasia123',
        ]);

        $this->kasir = $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Ani Kasir',
            'username' => 'ani',
            'pin_hash' => '123456',
            'outlet_id' => $this->outletA->getKey(),
            'device_id_terikat' => $this->perangkatA->getKey(),
        ]);
    }

    public function test_owner_masuk_lewat_email_dan_diarahkan_ke_back_office(): void
    {
        Livewire::test(Masuk::class)
            ->set('email', 'owner@warung.test')
            ->set('password', 'rahasia123')
            ->call('masuk')
            ->assertHasNoErrors()
            ->assertRedirect(route('owner.dasbor'));

        $this->assertTrue(Auth::check());
        $this->assertNotNull($this->owner->fresh()->last_login_at);
    }

    public function test_super_admin_diarahkan_ke_area_platform(): void
    {
        $this->buatSuperAdmin();

        Livewire::test(Masuk::class)
            ->set('email', 'super@nampan.test')
            ->set('password', 'rahasia123')
            ->call('masuk')
            ->assertRedirect(route('admin.dasbor'));
    }

    /**
     * Inti pengamanan: kalau kasir boleh masuk lewat jalur email, seluruh
     * pemeriksaan device binding bisa dilewati begitu saja.
     */
    public function test_kasir_ditolak_di_jalur_masuk_email(): void
    {
        $this->kasir->forceFill([
            'email' => 'ani@warung.test',
            'password' => bcrypt('rahasia123'),
        ])->save();

        Livewire::test(Masuk::class)
            ->set('email', 'ani@warung.test')
            ->set('password', 'rahasia123')
            ->call('masuk')
            ->assertHasErrors('email');

        $this->assertFalse(Auth::check());
    }

    public function test_kasir_masuk_dengan_pin_dari_perangkat_outletnya(): void
    {
        Livewire::test(MasukKasir::class)
            ->set('username', 'ani')
            ->set('pin', '123456')
            ->set('serialPerangkat', 'TAB-A')
            ->call('masuk')
            ->assertHasNoErrors()
            ->assertRedirect(route('kasir.beranda'));

        $this->assertSame($this->kasir->getKey(), Auth::id());
    }

    /**
     * Bagian 3.2.E dokumen: staff Outlet A yang mencoba login di perangkat Outlet B
     * harus DITOLAK, bukan diberi peringatan.
     */
    public function test_kasir_ditolak_saat_memakai_perangkat_outlet_lain(): void
    {
        Livewire::test(MasukKasir::class)
            ->set('username', 'ani')
            ->set('pin', '123456')
            ->set('serialPerangkat', 'TAB-B')
            ->call('masuk')
            ->assertHasErrors('username');

        $this->assertFalse(Auth::check());
    }

    public function test_kasir_yang_terikat_perangkat_ditolak_tanpa_nomor_seri(): void
    {
        Livewire::test(MasukKasir::class)
            ->set('username', 'ani')
            ->set('pin', '123456')
            ->set('serialPerangkat', '')
            ->call('masuk')
            ->assertHasErrors('username');

        $this->assertFalse(Auth::check());
    }

    public function test_perangkat_yang_dikunci_mdm_menolak_login(): void
    {
        $this->perangkatA->forceFill(['mdm_locked_at' => now()])->save();

        Livewire::test(MasukKasir::class)
            ->set('username', 'ani')
            ->set('pin', '123456')
            ->set('serialPerangkat', 'TAB-A')
            ->call('masuk')
            ->assertHasErrors('username');

        $this->assertFalse(Auth::check());
    }

    public function test_pin_salah_ditolak(): void
    {
        Livewire::test(MasukKasir::class)
            ->set('username', 'ani')
            ->set('pin', '999999')
            ->set('serialPerangkat', 'TAB-A')
            ->call('masuk')
            ->assertHasErrors('username');

        $this->assertFalse(Auth::check());
    }

    public function test_akun_nonaktif_ditolak(): void
    {
        $this->kasir->forceFill(['is_active' => false])->save();

        Livewire::test(MasukKasir::class)
            ->set('username', 'ani')
            ->set('pin', '123456')
            ->set('serialPerangkat', 'TAB-A')
            ->call('masuk')
            ->assertHasErrors('username');

        $this->assertFalse(Auth::check());
    }

    public function test_keluar_membersihkan_sesi(): void
    {
        $this->actingAs($this->owner)
            ->post(route('keluar'))
            ->assertRedirect(route('beranda'));

        $this->assertFalse(Auth::check());
    }
}
