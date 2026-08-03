<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mengisi TenantContext dari user yang sedang login.
 *
 * Tenant & outlet diambil MURNI dari record user di database — tidak pernah dari
 * header, query string, atau body request. Ini mencegah user tenant A memaksa
 * sistem membaca data tenant B (IDOR).
 */
class ResolveTenantContext
{
    public function __construct(protected TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isPlatformLevel()) {
            $this->context
                ->setTenant($user->tenant_id)
                ->setOutlet($user->scopedOutletId());
        }

        return $next($request);
    }
}
