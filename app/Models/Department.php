<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends TenantModel
{
    use SoftDeletes;

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * @return BelongsTo<StaffProfile, $this>
     */
    public function head(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'head_staff_profile_id');
    }

    /**
     * @return HasMany<StaffProfile, $this>
     */
    public function staff(): HasMany
    {
        return $this->hasMany(StaffProfile::class);
    }
}
