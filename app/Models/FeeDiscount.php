<?php

namespace App\Models;

use App\Enums\AdjustmentType;
use App\Models\Concerns\AuditsChanges;
use App\Support\Billing\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A discount or scholarship definition, assigned to individual students.
 */
class FeeDiscount extends TenantModel
{
    use AuditsChanges, SoftDeletes;

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
     * "10%" or "$500".
     */
    public function valueLabel(): string
    {
        return $this->type === AdjustmentType::Percentage
            ? rtrim(rtrim(number_format((float) $this->value, 2, '.', ''), '0'), '.').'%'
            : Money::school($this->value);
    }

    /**
     * @return HasMany<StudentFeeDiscount, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(StudentFeeDiscount::class);
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
