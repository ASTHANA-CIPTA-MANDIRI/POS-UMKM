<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Lapisan proteksi utama isolasi data antar merchant: setiap query pada model
 * ber-tenant otomatis difilter berdasarkan tenant di TenantContext.
 *
 * Catatan: PostgreSQL Row-Level Security yang disebut di prinsip 3 ERD tidak
 * tersedia karena proyek ini memakai MySQL, sehingga isolasi ditegakkan di level
 * aplikasi lewat scope ini. Konsekuensinya, query raw (DB::table/DB::select) TIDAK
 * ikut terfilter dan harus menambahkan filter tenant_id sendiri.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->shouldScope()) {
            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $context->tenantId());
    }
}
