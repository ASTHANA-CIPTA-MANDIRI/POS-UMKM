<?php

namespace Tests\Concerns;

use App\Enums\BusinessType;
use App\Enums\DeviceType;
use App\Enums\TenantStatus;
use App\Enums\TransactionMode;
use App\Enums\UserRole;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Penyiapan data minimum yang dipakai berulang di beberapa berkas test.
 *
 * Perhatikan: User TIDAK memakai trait BelongsToTenant (supaya query auth guard
 * tidak ikut terfilter), jadi tenant_id-nya harus diisi eksplisit di sini —
 * berbeda dari model lain yang terisi otomatis dari TenantContext.
 */
trait MembuatDataUji
{
    protected function konteks(): TenantContext
    {
        return app(TenantContext::class);
    }

    protected function buatTenant(
        string $nama = 'Warung Uji',
        TenantStatus $status = TenantStatus::Aktif,
        ?string $trialSampai = null,
        BusinessType $jenis = BusinessType::Fnb,
    ): Tenant {
        return Tenant::create([
            'business_name' => $nama,
            'business_type' => $jenis,
            'owner_name' => 'Pemilik '.$nama,
            'owner_phone' => '0812'.random_int(1000000, 9999999),
            'status' => $status,
            'trial_ends_at' => $trialSampai,
        ]);
    }

    protected function buatOutlet(Tenant $tenant, string $nama = 'Outlet Uji'): Outlet
    {
        return $this->konteks()->forTenant($tenant->getKey(), fn () => Outlet::create([
            'outlet_name' => $nama,
            'active_modes' => [TransactionMode::Langsung->value],
        ]));
    }

    protected function buatPerangkat(Tenant $tenant, Outlet $outlet, string $serial): Device
    {
        return $this->konteks()->forTenant($tenant->getKey(), fn () => Device::create([
            'outlet_id' => $outlet->getKey(),
            'device_type' => DeviceType::Tablet,
            'serial_number' => $serial,
        ]));
    }

    protected function buatUser(Tenant $tenant, UserRole $peran, array $atribut = []): User
    {
        $user = new User(array_merge([
            'name' => 'Pengguna '.$peran->value,
            'role' => $peran,
            'is_active' => true,
        ], $atribut));

        $user->tenant_id = $tenant->getKey();
        $user->save();

        return $user;
    }

    protected function buatSuperAdmin(array $atribut = []): User
    {
        $user = new User(array_merge([
            'name' => 'Super Admin',
            'email' => 'super@nampan.test',
            'password' => 'rahasia123',
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
        ], $atribut));

        $user->tenant_id = null;
        $user->save();

        return $user;
    }
}
