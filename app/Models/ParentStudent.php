<?php

namespace App\Models;

use App\Enums\GuardianRelation;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Parent ↔ student link. A tenant-owned pivot: attach() stamps school_id.
 */
class ParentStudent extends Pivot
{
    use BelongsToSchool;

    protected $table = 'parent_student';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'relation' => GuardianRelation::class,
            'is_primary' => 'boolean',
        ];
    }
}
