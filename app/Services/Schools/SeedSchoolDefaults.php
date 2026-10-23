<?php

namespace App\Services\Schools;

use App\Enums\LeaveAppliesTo;
use App\Enums\RecordStatus;
use App\Models\Campus;
use App\Models\LeaveType;
use App\Models\School;

/**
 * Records every school starts with. Safe to run again: anything already
 * there (or deleted by the school) is left alone. Runs inside the school's
 * context.
 */
class SeedSchoolDefaults
{
    /**
     * name => [code, applies_to, days_per_year (null = no quota), is_paid]
     */
    public const LEAVE_TYPES = [
        'Sick Leave' => ['SL', LeaveAppliesTo::Both, 10, true],
        'Casual Leave' => ['CL', LeaveAppliesTo::Staff, 10, true],
        'Unpaid Leave' => ['UL', LeaveAppliesTo::Staff, null, false],
        'Family Emergency' => ['FE', LeaveAppliesTo::Student, null, true],
    ];

    public function handle(School $school): void
    {
        tenancy()->run($school, function () {
            if (Campus::query()->withTrashed()->doesntExist()) {
                Campus::query()->create([
                    'name' => __('Main Campus'),
                    'is_main' => true,
                    'status' => RecordStatus::Active,
                ]);
            }

            foreach (self::LEAVE_TYPES as $name => [$code, $appliesTo, $days, $paid]) {
                LeaveType::query()->withTrashed()->firstOrCreate(['name' => $name], [
                    'code' => $code,
                    'applies_to' => $appliesTo,
                    'days_per_year' => $days,
                    'is_paid' => $paid,
                    'is_active' => true,
                ]);
            }
        });
    }
}
