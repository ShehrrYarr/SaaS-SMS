<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a portal route group: `portal:teacher` requires the portal.teacher
 * permission in the active school.
 */
class EnsurePortalAccess
{
    public function handle(Request $request, Closure $next, string $portal): Response
    {
        abort_unless($request->user()?->canAccessPortal($portal), 403);

        return $next($request);
    }
}
