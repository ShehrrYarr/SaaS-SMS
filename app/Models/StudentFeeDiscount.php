<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A discount assigned to a student, optionally limited to a date window.
 */
class StudentFeeDiscount extends Pivot
{
    use BelongsToSchool;

    protected $table = 'student_fee_discounts';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    /**
     * @return BelongsTo<StudentProfile, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    /**
     * @return BelongsTo<FeeDiscount, $this>
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(FeeDiscount::class, 'fee_discount_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
