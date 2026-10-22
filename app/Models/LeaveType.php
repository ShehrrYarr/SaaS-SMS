<?php

namespace App\Models;

use App\Enums\LeaveAppliesTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveType extends TenantModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'applies_to' => LeaveAppliesTo::class,
            'days_per_year' => 'integer',
            'is_paid' => 'boolean',
            'requires_attachment' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<LeaveRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
