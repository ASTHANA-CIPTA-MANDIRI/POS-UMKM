<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Satu tabel untuk semua aktor: Super Admin (tenant_id NULL) sampai Kasir.
 *
 * Sengaja TIDAK memakai trait BelongsToTenant: global scope tenant akan ikut
 * membatasi query auth guard (retrieveById/retrieveByCredentials) dan membuat
 * Super Admin tidak bisa mengelola user lintas tenant. Isolasi user antar tenant
 * ditegakkan lewat relasi & policy, bukan global scope.
 */
#[Fillable([
    'name', 'email', 'password', 'username', 'pin_hash', 'role',
    'is_active', 'outlet_id', 'device_id_terikat',
])]
#[Hidden(['password', 'pin_hash', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    /** Disamakan dengan default di migrasi. */
    protected $attributes = [
        'role' => 'kasir',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin_hash' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Outlet tunggal tempat staff dikunci (NULL untuk Owner/Regional Manager). */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** Outlet yang di-assign ke Area/Regional Manager. */
    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class)->withTimestamps();
    }

    public function deviceTerikat(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id_terikat');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'staff_id');
    }

    public function cashSessions(): HasMany
    {
        return $this->hasMany(CashSession::class, 'staff_id');
    }

    public function shiftLogs(): HasMany
    {
        return $this->hasMany(ShiftLog::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function isPlatformLevel(): bool
    {
        return $this->role->isPlatformLevel();
    }

    /**
     * Outlet yang dipakai untuk memfilter data. NULL berarti user boleh melihat
     * lebih dari satu outlet (Owner/Regional Manager), sehingga pembatasan outlet
     * ditentukan per halaman, bukan lewat context.
     */
    public function scopedOutletId(): ?string
    {
        return $this->role->requiresOutlet() ? $this->outlet_id : null;
    }

    /**
     * Staff Outlet A tidak boleh mengakses Outlet B — hard block, bukan warning.
     * Dipanggil dari middleware/policy, bukan mengandalkan pilihan dari client.
     */
    public function canAccessOutlet(string $outletId): bool
    {
        if ($this->isPlatformLevel()) {
            return true;
        }

        return match ($this->role) {
            UserRole::Owner => Outlet::query()
                ->whereKey($outletId)
                ->where('tenant_id', $this->tenant_id)
                ->exists(),
            UserRole::RegionalManager => $this->outlets()
                ->whereKey($outletId)
                ->exists(),
            default => $this->outlet_id === $outletId,
        };
    }

    /**
     * Halaman tujuan sesudah login. Ditentukan dari peran, bukan dari input, supaya
     * tidak bisa diarahkan ke area yang bukan haknya lewat parameter redirect.
     */
    public function rutaBeranda(): string
    {
        return match ($this->role) {
            UserRole::SuperAdmin => 'admin.dasbor',
            UserRole::Owner, UserRole::RegionalManager, UserRole::ManagerOutlet => 'owner.dasbor',
            UserRole::Kasir, UserRole::Dapur => 'kasir.beranda',
        };
    }

    /** Boleh masuk area back office merchant (bukan kasir). */
    public function bolehKeBackOffice(): bool
    {
        return in_array($this->role, [
            UserRole::Owner,
            UserRole::RegionalManager,
            UserRole::ManagerOutlet,
        ], true);
    }

    /** Validasi device binding: staff hanya boleh login dari perangkat outletnya. */
    public function canLoginFromDevice(Device $device): bool
    {
        if (! $device->canBeUsedForLogin()) {
            return false;
        }

        if ($this->device_id_terikat !== null) {
            return $this->device_id_terikat === $device->id;
        }

        return $this->canAccessOutlet($device->outlet_id);
    }
}
