<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PayrollStatus;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One staff member's pay for one month. Draft (editable) → Approved
 * (locked) → Paid (posted to expenses, payslip available to the staff
 * member).
 *
 * breakdown: {
 *   allowances: [{label, amount, auto?}], deductions: [{label, amount, auto?}],
 *   attendance: {working_days, present, absent, half_days, paid_leave, unpaid_leave, not_employed}
 * }
 */
class PayrollRecord extends TenantModel
{
    use AuditsChanges, SoftDeletes;

    /** Generation is logged once per run by the Payroll service; changes per record. */
    protected static $recordEvents = ['updated', 'deleted'];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:2',
            'bonuses' => 'decimal:2',
            'deductions' => 'decimal:2',
            'net_salary' => 'decimal:2',
            'breakdown' => 'array',
            'working_days' => 'integer',
            'present_days' => 'decimal:1',
            'status' => PayrollStatus::class,
            'paid_on' => 'date',
            'payment_method' => PaymentMethod::class,
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === PayrollStatus::Draft;
    }

    public function isPaid(): bool
    {
        return $this->status === PayrollStatus::Paid;
    }

    /**
     * "October 2026".
     */
    public function monthLabel(): string
    {
        return Carbon::createFromFormat('!Y-m', $this->month_year)->translatedFormat('F Y');
    }

    /**
     * @return BelongsTo<StaffProfile, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * The salary expense posted when this record was paid.
     *
     * @return MorphOne<Expense, $this>
     */
    public function expense(): MorphOne
    {
        return $this->morphOne(Expense::class, 'source');
    }
}
