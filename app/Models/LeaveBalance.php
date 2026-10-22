<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends TenantModel
{
    protected function casts(): array
    {
        return [
            'allocated' => 'decimal:1',
            'used' => 'decimal:1',
        ];
    }

    /**
     * @return Attribute<float, never>
     */
    protected function remaining(): Attribute
    {
        return Attribute::get(fn () => (float) $this->allocated - (float) $this->used);
    }

    /**
     * @return BelongsTo<StaffProfile, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
