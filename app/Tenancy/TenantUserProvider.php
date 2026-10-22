<?php

namespace App\Tenancy;

use App\Models\Scopes\TenantScope;
use Illuminate\Auth\EloquentUserProvider;

/**
 * Resolves the session user without the tenant scope.
 *
 * Auth may resolve the user before the tenant middleware has run, so it can't
 * depend on the tenant context. Isolation is enforced elsewhere: login always
 * filters by school_id explicitly, and EnsureTenantContext signs out any user
 * whose school doesn't match the URL.
 */
class TenantUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope(TenantScope::class);
    }
}
