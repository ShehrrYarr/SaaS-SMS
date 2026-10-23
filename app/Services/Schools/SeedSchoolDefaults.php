<?php

namespace App\Services\Schools;

use App\Enums\FinanceCategoryType;
use App\Enums\LeaveAppliesTo;
use App\Enums\RecordStatus;
use App\Models\Campus;
use App\Models\FinanceCategory;
use App\Models\GradeScale;
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

    /**
     * The default grade scale: grade => [starts at %, grade point, remark].
     */
    public const GRADES = [
        'A+' => [90, 4.0, 'Outstanding'],
        'A' => [80, 3.7, 'Excellent'],
        'B' => [70, 3.0, 'Very good'],
        'C' => [60, 2.5, 'Good'],
        'D' => [50, 2.0, 'Satisfactory'],
        'E' => [40, 1.0, 'Pass'],
        'F' => [0, 0.0, 'Fail'],
    ];

    /**
     * Income and expense headings: name => [type, system key]. Fee payments
     * and paid salaries post to the system ones automatically.
     */
    public const FINANCE_CATEGORIES = [
        'Fee Collection' => [FinanceCategoryType::Income, FinanceCategory::FEES],
        'Donations' => [FinanceCategoryType::Income, null],
        'Other Income' => [FinanceCategoryType::Income, null],
        'Salaries' => [FinanceCategoryType::Expense, FinanceCategory::SALARIES],
        'Utilities' => [FinanceCategoryType::Expense, null],
        'Rent' => [FinanceCategoryType::Expense, null],
        'Maintenance' => [FinanceCategoryType::Expense, null],
        'Stationery & Supplies' => [FinanceCategoryType::Expense, null],
        'Transport' => [FinanceCategoryType::Expense, null],
        'Events' => [FinanceCategoryType::Expense, null],
        'Other Expenses' => [FinanceCategoryType::Expense, null],
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

            if (GradeScale::query()->withTrashed()->doesntExist()) {
                $this->seedGradeScale();
            }

            $this->seedFinanceCategories();
        });
    }

    /**
     * The defaults on a school's first run; afterwards only missing system
     * categories are put back, since the ledger depends on them.
     */
    private function seedFinanceCategories(): void
    {
        $fresh = FinanceCategory::query()->withTrashed()->doesntExist();

        foreach (self::FINANCE_CATEGORIES as $name => [$type, $key]) {
            if ($key !== null) {
                $category = FinanceCategory::query()->withTrashed()->where('system_key', $key)->first();

                if ($category?->trashed()) {
                    $category->restore();
                }

                $category ?? FinanceCategory::query()->withTrashed()->updateOrCreate(
                    ['type' => $type, 'name' => __($name)],
                    ['system_key' => $key, 'is_active' => true, 'deleted_at' => null],
                );
            } elseif ($fresh) {
                FinanceCategory::query()->create(['type' => $type, 'name' => __($name), 'is_active' => true]);
            }
        }
    }

    private function seedGradeScale(): void
    {
        $scale = GradeScale::query()->create(['name' => __('Standard'), 'is_default' => true]);
        $ceiling = 100.0;
        $order = 0;

        foreach (self::GRADES as $grade => [$from, $point, $remark]) {
            $scale->items()->create([
                'grade' => $grade,
                'min_percentage' => $from,
                'max_percentage' => $ceiling,
                'grade_point' => $point,
                'remark' => __($remark),
                'sort_order' => $order++,
            ]);

            $ceiling = round($from - 0.01, 2);
        }
    }
}
