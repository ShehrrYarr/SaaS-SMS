<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Activity log entry, stamped with the school it happened in. Entries made in
 * the central console have no school.
 */
class Activity extends SpatieActivity
{
    use BelongsToSchool;

    public function requiresSchool(): bool
    {
        return false;
    }
}
