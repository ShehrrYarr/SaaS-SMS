<?php

use App\Models\School;
use App\Tenancy\TenantContext;

if (! function_exists('tenancy')) {
    function tenancy(): TenantContext
    {
        return app(TenantContext::class);
    }
}

if (! function_exists('tenant')) {
    /**
     * The school the current request is acting for, if any.
     */
    function tenant(): ?School
    {
        return app(TenantContext::class)->school();
    }
}
