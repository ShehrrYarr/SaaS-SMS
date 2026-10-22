<?php

namespace App\Models;

use App\Enums\AdjustmentType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A discount or scholarship definition, assigned to individual students.
 */
class FeeDiscount extends TenantModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => AdjustmentType::class,
            'value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Restricts the discount to one fee head; null applies to any fee that
     * allows discounts.
     *
     * @return BelongsTo<FeeStructure, $this>
     */
    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }

    /**
     * @return BelongsToMany<StudentProfile, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(StudentProfile::class, 'student_fee_discounts')
            ->using(StudentFeeDiscount::class)
            ->withPivot(['id', 'valid_from', 'valid_until', 'remarks', 'approved_by'])
            ->withTimestamps();
    }
}
