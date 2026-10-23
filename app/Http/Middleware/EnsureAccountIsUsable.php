<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after `auth` on school routes:
 *  - a deactivated account is signed out on its next request;
 *  - an account with an admin-set temporary password must choose a new one
 *    before it can use anything else.
 */
class EnsureAccountIsUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if (! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('tenant.login')
                ->withErrors(['login' => __('This account is not active. Please contact your school administrator.')]);
        }

        if ($user->must_change_password && ! $request->routeIs('tenant.password.change')) {
            return redirect()->route('tenant.password.change');
        }

        return $next($request);
    }
}
