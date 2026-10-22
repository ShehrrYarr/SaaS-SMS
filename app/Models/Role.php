<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A school role (Spatie team role; school_id is the team key).
 *
 * Deliberately NOT tenant-scoped: Spatie caches the permission → role map
 * globally, and a global scope here would cache one school's view of it for
 * every school. Query a school's roles with Role::forCurrentSchool().
 */
class Role extends SpatieRole
{
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function scopeForCurrentSchool(Builder $query): void
    {
        $query->where($this->qualifyColumn('school_id'), tenancy()->id());
    }
}
