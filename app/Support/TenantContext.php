<?php

namespace App\Support;

use Closure;

/**
 * Menyimpan tenant & outlet aktif untuk request/proses yang sedang berjalan.
 *
 * Nilainya HANYA boleh diisi dari sesi login (lihat ResolveTenantContext), tidak
 * pernah dari input client — ini pertahanan utama terhadap IDOR sesuai checklist
 * bagian 6 dokumen bisnis.
 *
 * Didaftarkan sebagai singleton di AppServiceProvider.
 */
class TenantContext
{
    protected ?string $tenantId = null;

    protected ?string $outletId = null;

    protected bool $scopingDisabled = false;

    public function setTenant(?string $tenantId): static
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function setOutlet(?string $outletId): static
    {
        $this->outletId = $outletId;

        return $this;
    }

    public function outletId(): ?string
    {
        return $this->outletId;
    }

    public function hasOutlet(): bool
    {
        return $this->outletId !== null;
    }

    /**
     * Global scope hanya aktif kalau ada tenant di context. Super Admin, perintah
     * artisan, dan queue job berjalan tanpa tenant sehingga bisa melihat lintas
     * tenant — konsekuensinya query di jalur tersebut wajib memfilter sendiri.
     */
    public function shouldScope(): bool
    {
        return $this->hasTenant() && ! $this->scopingDisabled;
    }

    /**
     * Jalankan sesuatu tanpa filter tenant, lalu pulihkan keadaan sebelumnya.
     * Dipakai untuk laporan lintas tenant Super Admin & job terjadwal.
     */
    public function withoutScoping(Closure $callback): mixed
    {
        $previous = $this->scopingDisabled;
        $this->scopingDisabled = true;

        try {
            return $callback();
        } finally {
            $this->scopingDisabled = $previous;
        }
    }

    /** Jalankan sesuatu sebagai tenant tertentu (impersonate/seeder/job). */
    public function forTenant(?string $tenantId, Closure $callback): mixed
    {
        $previousTenant = $this->tenantId;
        $previousOutlet = $this->outletId;

        $this->tenantId = $tenantId;
        $this->outletId = null;

        try {
            return $callback();
        } finally {
            $this->tenantId = $previousTenant;
            $this->outletId = $previousOutlet;
        }
    }

    public function forget(): static
    {
        $this->tenantId = null;
        $this->outletId = null;
        $this->scopingDisabled = false;

        return $this;
    }
}
