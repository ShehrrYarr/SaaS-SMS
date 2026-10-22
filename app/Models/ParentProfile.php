<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ParentProfile extends TenantModel
{
    use SoftDeletes;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<StudentProfile, $this>
     */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(StudentProfile::class, 'parent_student')
            ->using(ParentStudent::class)
            ->withPivot(['id', 'relation', 'is_primary'])
            ->withTimestamps();
    }
}
