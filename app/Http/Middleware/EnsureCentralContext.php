<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the request in the central (Super Admin) context: tenant scoping is
 * lifted so the console can work across every school.
 */
class EnsureCentralContext
{
    public function __construct(private readonly TenantContext $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenancy->enterCentral();

        return $next($request);
    }
}
