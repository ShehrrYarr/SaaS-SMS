<?php

use App\Http\Middleware\EnsureAccountIsUsable;
use App\Http\Middleware\EnsureCentralContext;
use App\Http\Middleware\EnsurePortalAccess;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantContext;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')->group(base_path('routes/central.php'));
            Route::middleware('web')->group(base_path('routes/tenant.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant' => EnsureTenantContext::class,
            'central' => EnsureCentralContext::class,
            'super-admin' => EnsureSuperAdmin::class,
            'portal' => EnsurePortalAccess::class,
            'account' => EnsureAccountIsUsable::class,
        ]);

        // Resolve the tenant (or central context) before authentication and
        // route-model binding, so both always run inside the right school.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureTenantContext::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureCentralContext::class);

        // Guests go to the sign-in page of the school (or console) they asked for;
        // signed-in users who open a sign-in page go to their home.
        $middleware->redirectGuestsTo(fn () => tenant() ? route('tenant.login') : route('central.login'));
        $middleware->redirectUsersTo(fn () => tenant() ? route('tenant.home') : route('central.dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
