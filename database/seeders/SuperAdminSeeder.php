<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Pemilik platform. tenant_id sengaja NULL — Super Admin bukan milik merchant
 * mana pun, dan justru karena itu global scope tenant tidak berlaku untuknya.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::withTrashed()->firstOrNew(['email' => 'superadmin@pos-umkm.test']);

        $user->fill([
            'name' => 'Super Admin',
            // Kredensial demo untuk pengembangan lokal saja.
            'password' => 'password',
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
        ]);

        $user->tenant_id = null;
        $user->email_verified_at = now();
        $user->deleted_at = null;
        $user->save();
    }
}
