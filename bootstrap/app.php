<?php

use App\Http\Middleware\EnsureCentralContext;
use App\Http\Middleware\EnsurePortalAccess;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantContext;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant' => EnsureTenantContext::class,
            'central' => EnsureCentralContext::class,
            'super-admin' => EnsureSuperAdmin::class,
            'portal' => EnsurePortalAccess::class,
        ]);

        // Resolve the tenant (or central context) before authentication and
        // route-model binding, so both always run inside the right school.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureTenantContext::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureCentralContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
