<?php

namespace Database\Seeders;

use App\Enums\AcademicPeriodStatus;
use App\Enums\PaymentMethod;
use App\Enums\RecordStatus;
use App\Enums\SchoolStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\Plan;
use App\Models\School;
use App\Models\User;
use App\Services\Access\SyncDefaultSchoolRoles;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Database\Seeder;

/**
 * LOCAL DEVELOPMENT ONLY. A minimal school to sign in to while the portals are
 * built: /greenfield/login as "admin" with the password "password".
 * The full demo data seeder (students, attendance, marks, invoices) comes
 * once those modules exist.
 */
class DemoSchoolSeeder extends Seeder
{
    public const SLUG = 'greenfield';

    public const ADMIN_USERNAME = 'admin';

    public const PASSWORD = 'password';

    public function run(SyncDefaultSchoolRoles $syncRoles, SubscriptionManager $subscriptions): void
    {
        $school = School::query()->firstOrCreate(['slug' => self::SLUG], [
            'name' => 'Greenfield Academy',
            'email' => 'office@greenfield.test',
            'phone' => '+1 555 0100',
            'city' => 'Springfield',
            'country' => 'United States',
            'status' => SchoolStatus::Active,
            'plan_id' => Plan::query()->where('slug', 'premium')->value('id'),
        ]);

        $syncRoles->handle($school);

        tenancy()->run($school, function (): void {
            Campus::query()->firstOrCreate(['name' => 'Main Campus'], ['is_main' => true, 'status' => RecordStatus::Active]);

            $year = now()->month >= 8 ? now()->year : now()->year - 1;

            AcademicYear::query()->firstOrCreate(['name' => $year.'-'.($year + 1)], [
                'start_date' => "{$year}-08-01",
                'end_date' => ($year + 1).'-06-30',
                'status' => AcademicPeriodStatus::Current,
            ]);

            $admin = User::query()->firstOrCreate(['username' => self::ADMIN_USERNAME], [
                'name' => 'Greenfield Admin',
                'email' => 'admin@greenfield.test',
                'password' => self::PASSWORD,
                'user_type' => UserType::SchoolAdmin,
                'status' => UserStatus::Active,
            ]);

            $admin->assignRole(UserType::SchoolAdmin->defaultRole());
        });

        // A paid month, so the school shows as subscribed.
        if (tenancy()->run($school, fn () => $school->subscriptionPayments()->doesntExist())) {
            [$start, $end] = $subscriptions->suggestPeriod($school);

            $subscriptions->recordPayment($school, [
                'plan_id' => $school->plan_id,
                'amount' => $school->plan?->price ?? 0,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'paid_on' => today()->toDateString(),
                'payment_method' => PaymentMethod::BankTransfer,
                'reference' => 'DEMO-0001',
            ]);
        }

        $this->command?->info('Demo school: /'.self::SLUG.'/login  (admin / '.self::PASSWORD.')');
    }
}
