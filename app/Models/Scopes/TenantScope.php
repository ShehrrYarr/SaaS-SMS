<?php

namespace App\Models\Scopes;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(TenantContext::class);

        if ($tenancy->hasSchool()) {
            $builder->where($model->qualifyColumn('school_id'), $tenancy->id());

            return;
        }

        if ($tenancy->isCentral()) {
            return;
        }

        // No tenant resolved: fail closed instead of leaking every school's rows.
        $builder->whereRaw('1 = 0');
    }
}
