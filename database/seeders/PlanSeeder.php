<?php

namespace Database\Seeders;

use App\Enums\BillingCycle;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['name' => 'Starter', 'slug' => 'starter', 'price' => 49, 'max_students' => 300, 'max_staff' => 30, 'sort_order' => 1,
                'modules' => ['academics', 'students', 'attendance', 'examinations', 'fees', 'notices']],
            ['name' => 'Standard', 'slug' => 'standard', 'price' => 99, 'max_students' => 1000, 'max_staff' => 100, 'sort_order' => 2,
                'modules' => ['academics', 'students', 'attendance', 'examinations', 'homework', 'fees', 'hr', 'payroll', 'notices']],
            ['name' => 'Premium', 'slug' => 'premium', 'price' => 199, 'max_students' => null, 'max_staff' => null, 'sort_order' => 3,
                'modules' => null],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(
                ['slug' => $plan['slug']],
                $plan + ['billing_cycle' => BillingCycle::Monthly, 'currency' => 'USD', 'trial_days' => 14, 'is_active' => true],
            );
        }
    }
}
