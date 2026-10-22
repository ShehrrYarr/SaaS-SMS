<?php

namespace App\Http\Middleware;

use App\Models\School;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the school from the /{school} path prefix and makes it the active
 * tenant for the rest of the request.
 *
 * Registered as Livewire persistent middleware, so it also runs on
 * /livewire/update calls made from a tenant page.
 */
class EnsureTenantContext
{
    public function __construct(private readonly TenantContext $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $parameter = config('tenancy.route_parameter');
        $slug = $route?->parameter($parameter);

        $school = $slug instanceof School
            ? $slug
            : School::query()->where('slug', (string) $slug)->first();

        abort_if($school === null, 404);

        // Components and controllers never receive {school}; they read tenant().
        $route->forgetParameter($parameter);

        abort_if($school->isSuspended(), 403, __('This school account is suspended. Please contact support.'));

        $this->tenancy->set($school);

        // One session is one account. A session from another school (or the
        // platform console) is signed out rather than carried across.
        $user = Auth::guard('web')->user();

        if ($user !== null && (int) $user->school_id !== $school->getKey()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $next($request);
    }
}
