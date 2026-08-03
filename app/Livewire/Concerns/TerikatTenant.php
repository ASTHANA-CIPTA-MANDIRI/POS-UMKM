<?php

namespace App\Livewire\Concerns;

use App\Support\TenantContext;

/**
 * Mengikat komponen ke tenant milik user yang login.
 *
 * KENAPA INI ADA, padahal sudah ada middleware ResolveTenantContext:
 *
 * Komponen Livewire adalah titik masuk tersendiri, sama seperti controller. Middleware
 * memang mengisi TenantContext pada request HTTP biasa, tapi begitu ada satu jalur yang
 * melewatinya — pemanggilan dari test, dari command, atau dari kode lain yang me-render
 * komponen secara langsung — TenantContext kosong. Dan saat kosong, TenantScope memilih
 * TIDAK memfilter apa pun. Artinya query dasbor mengembalikan data SELURUH merchant.
 *
 * Kebocoran itu bukan hipotesis: test isolasi dasbor menangkapnya, dan gejalanya adalah
 * angka omzet yang membengkak karena berisi omzet merchant lain.
 *
 * Jadi setiap komponen yang menampilkan data merchant memakai trait ini. Middleware
 * tetap dipertahankan sebagai lapis pertama; ini lapis kedua yang berdiri sendiri.
 */
trait TerikatTenant
{
    public function bootTerikatTenant(): void
    {
        $user = auth()->user();

        if ($user === null || $user->tenant_id === null) {
            return;
        }

        app(TenantContext::class)
            ->setTenant($user->tenant_id)
            ->setOutlet($user->scopedOutletId());
    }
}
