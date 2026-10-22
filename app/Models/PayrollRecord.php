<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PayrollStatus;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollRecord extends TenantModel
{
    use AuditsChanges, SoftDeletes;

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
}
