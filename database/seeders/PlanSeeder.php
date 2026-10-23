<?php

namespace Database\Seeders;

use App\Enums\BillingCycle;
use App\Models\Plan;
use App\Support\Billing\Money;
use Illuminate\Database\Seeder;

/**
 * Starting plans. Prices and limits are placeholders: edit them in the
 * console under Billing > Plans.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter', 'slug' => 'starter', 'price' => 49, 'max_students' => 300, 'max_staff' => 30, 'sort_order' => 1,
                'description' => 'Small schools: academics, attendance, exams and fees.',
                'features' => ['examinations', 'finance'],
            ],
            [
                'name' => 'Standard', 'slug' => 'standard', 'price' => 99, 'max_students' => 1000, 'max_staff' => 100, 'sort_order' => 2,
                'description' => 'Adds homework, HR, staff leave and payroll.',
                'features' => ['examinations', 'homework', 'finance', 'hr', 'payroll'],
            ],
            [
                'name' => 'Premium', 'slug' => 'premium', 'price' => 199, 'max_students' => null, 'max_staff' => null, 'sort_order' => 3,
                'description' => 'Everything, with no limits.',
                'features' => null,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(
                ['slug' => $plan['slug']],
                $plan + ['billing_cycle' => BillingCycle::Monthly, 'currency' => Money::currency(), 'trial_days' => 14, 'is_active' => true],
            );
        }
    }
}
