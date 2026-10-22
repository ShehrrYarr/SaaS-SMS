<?php

namespace App\Models\Concerns;

use App\Models\School;
use App\Models\Scopes\TenantScope;
use App\Tenancy\Exceptions\TenantContextException;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Makes a model tenant-owned.
 *
 * - Every query is filtered to the active school (TenantScope).
 * - New rows are stamped with the active school's id.
 * - A row can't be written into another school, or moved between schools.
 * - school_id is never mass-assignable.
 */
trait BelongsToSchool
{
    public static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $current = app(TenantContext::class)->id();

            if ($model->getAttribute('school_id') === null) {
                $model->setAttribute('school_id', $current);
            }

            if ($current !== null && (int) $model->getAttribute('school_id') !== $current) {
                throw TenantContextException::mismatch($model, $current);
            }

            if ($model->getAttribute('school_id') === null && $model->requiresSchool()) {
                throw TenantContextException::missing($model);
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('school_id') && $model->getOriginal('school_id') !== null) {
                throw TenantContextException::reassignment($model);
            }
        });
    }

    public function initializeBelongsToSchool(): void
    {
        $this->mergeGuarded(['school_id']);
    }

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Whether a row must belong to a school. Override for rows that may be
     * platform-level (super admin users, central activity log entries).
     */
    public function requiresSchool(): bool
    {
        return true;
    }
}
