<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dipakai semua model data merchant. Menambahkan filter tenant otomatis pada
 * setiap query, dan mengisi tenant_id saat record dibuat.
 *
 * tenant_id sengaja TIDAK dimasukkan ke daftar fillable model mana pun, supaya
 * tidak bisa di-override lewat mass assignment dari request.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            if ($tenantId = app(TenantContext::class)->tenantId()) {
                $model->setAttribute('tenant_id', $tenantId);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
