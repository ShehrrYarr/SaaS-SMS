<?php

namespace App\Http\Middleware;

use App\Support\Portal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a portal route group: `portal:teacher` requires the portal.teacher
 * permission in the active school, and marks "teacher" as the current portal.
 */
class EnsurePortalAccess
{
    public function handle(Request $request, Closure $next, string $portal): Response
    {
        abort_unless($request->user()?->canAccessPortal($portal), 403);

        Portal::setCurrent($portal);

        return $next($request);
    }
}
