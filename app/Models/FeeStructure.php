<?php

namespace App\Models;

use App\Enums\FeeFrequency;
use App\Enums\LateFeeType;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A fee head (Tuition, Transport, Admission) for one class or every class.
 * A null late_fee_type means "use the school's default late-fee rule".
 */
class FeeStructure extends TenantModel
{
    use AuditsChanges, SoftDeletes;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'frequency' => FeeFrequency::class,
            'due_day' => 'integer',
            'due_date' => 'date',
            'late_fee_type' => LateFeeType::class,
            'late_fee_amount' => 'decimal:2',
            'late_fee_grace_days' => 'integer',
            'late_fee_max' => 'decimal:2',
            'allow_discounts' => 'boolean',
            'allow_sibling_discount' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return HasMany<FeeDiscount, $this>
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(FeeDiscount::class);
    }

    /**
     * @return HasMany<SiblingDiscountRule, $this>
     */
    public function siblingDiscountRules(): HasMany
    {
        return $this->hasMany(SiblingDiscountRule::class);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
