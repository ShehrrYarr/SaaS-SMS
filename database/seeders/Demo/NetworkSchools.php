<?php

namespace Database\Seeders\Demo;

use App\Enums\AcademicPeriodStatus;
use App\Enums\Gender;
use App\Enums\PaymentMethod;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Plan;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Billing\SubscriptionManager;
use App\Services\Schools\ProvisionSchool;
use App\Services\Schools\SchoolLifecycle;
use App\Services\Staff\StaffRecords;
use App\Services\Students\StudentRecords;
use Carbon\CarbonImmutable;

/**
 * Smaller schools on the platform, so the super-admin console has a network
 * to show: one paying monthly, one on its free trial, one suspended after
 * its subscription ran out. Each has classes, a few teachers and students
 * with their parents; nothing else.
 */
final class NetworkSchools
{
    /**
     * slug => [name, city, plan, joined (days ago), state, classes, teachers, students]
     */
    public const SCHOOLS = [
        'oakridge' => ['Oakridge School System', 'Islamabad', 'standard', 150, 'paying', ['Grade 1', 'Grade 2', 'Grade 3', 'Grade 4'], 6, 64],
        'crescent' => ['Crescent Model School', 'Karachi', 'starter', 6, 'trial', ['Nursery', 'Prep', 'Grade 1'], 3, 26],
        'sunrise' => ['Sunrise Montessori', 'Multan', 'starter', 120, 'lapsed', ['Playgroup', 'Nursery', 'Prep'], 3, 30],
    ];

    private Dice $dice;

    public function __construct(private readonly string $password, private readonly float $scale = 1.0) {}

    public function build(string $slug, CarbonImmutable $now): School
    {
        [$name, $city, $planSlug, $daysAgo, $state, $classes, $teachers, $students] = self::SCHOOLS[$slug];

        $this->dice = new Dice(crc32($slug));
        $timeline = new Timeline($now);
        $superAdmin = tenancy()->runCentral(fn () => User::query()->where('user_type', UserType::SuperAdmin)->orderBy('id')->first());
        $plan = Plan::query()->where('slug', $planSlug)->first();
        $joined = $now->setTimezone('Asia/Karachi')->subDays($daysAgo)->setTime(11, 0);

        $school = $timeline->travel($joined, fn () => app(ProvisionSchool::class)->handle([
            'name' => $name,
            'slug' => $slug,
            'email' => 'office@'.$slug.'.test',
            'phone' => '+92 '.$this->dice->int(21, 91).' '.$this->dice->digits(7),
            'city' => $city,
            'country' => 'Pakistan',
            'plan_id' => $plan?->id,
            'trial_ends_at' => $joined->addDays($plan?->trial_days ?: 14)->toDateString(),
            'settings' => ['currency_code' => 'PKR', 'currency_symbol' => 'Rs', 'timezone' => 'Asia/Karachi', 'attendance' => ['weekly_off' => '7']],
        ], [
            'name' => $this->dice->pick(DemoData::MOTHERS).' '.$this->dice->pick(DemoData::SURNAMES),
            'username' => 'admin',
            'email' => 'principal@'.$slug.'.test',
        ], $this->password, by: $superAdmin)->school);

        $timeline->travel($joined->addHours(2), fn () => tenancy()->run($school, fn () => $this->populate($school, $joined, $classes, $teachers, $students)));

        // Subscription: monthly payments while paying; none after a lapse.
        $manager = app(SubscriptionManager::class);
        $start = CarbonImmutable::parse($school->trial_ends_at->toDateString())->addDay();
        $payments = match ($state) {
            'paying' => PHP_INT_MAX,
            'lapsed' => 2,
            default => 0,
        };

        // A paying school stays paid up beyond the renewal warning.
        $paidUpTo = $now->addDays((int) config('plans.subscriptions.warning_days') + 7);

        for ($i = 0; $i < $payments && $start->lte($paidUpTo); $i++) {
            $end = $start->addMonthNoOverflow()->subDay();
            $paidOn = $start->subDays(2)->setTime(12, 0)->min($now->subDay());

            $timeline->travel($paidOn, fn () => $manager->recordPayment($school->refresh(), [
                'plan_id' => $school->plan_id,
                'amount' => $school->plan?->price ?? 0,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'paid_on' => $paidOn->toDateString(),
                'payment_method' => PaymentMethod::BankTransfer,
                'reference' => strtoupper($slug).'-'.sprintf('%03d', $i + 1),
            ], $superAdmin));

            $start = $end->addDay();
        }

        if ($state === 'lapsed') {
            $timeline->travel($start->addDay()->setTime(0, 30), fn () => app(SchoolLifecycle::class)->suspend(
                $school->refresh(), __('Subscription ended on :date.', ['date' => $start->subDay()->format('d M Y')]),
            ));
        }

        return $school->refresh();
    }

    /**
     * @param  list<string>  $classes
     */
    private function populate(School $school, CarbonImmutable $joined, array $classes, int $teachers, int $students): void
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        auth()->setUser($admin);

        $yearStart = $joined->month >= 8 ? $joined->setDate($joined->year, 8, 1) : $joined->setDate($joined->year - 1, 8, 1);

        AcademicYear::query()->create([
            'name' => $yearStart->year.'-'.($yearStart->year + 1),
            'start_date' => $yearStart->toDateString(),
            'end_date' => $yearStart->addYear()->subMonth()->endOfMonth()->toDateString(),
            'status' => AcademicPeriodStatus::Current,
        ]);

        $campus = $school->campuses()->first() ?? throw new \RuntimeException('The school has no campus.');
        $sections = [];

        foreach ($classes as $level => $className) {
            $class = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => $className, 'numeric_level' => $level, 'sort_order' => $level]);
            $sections[] = $class->sections()->create(['name' => 'A', 'capacity' => 30]);
        }

        for ($i = 0; $i < $teachers; $i++) {
            $female = $this->dice->chance(70);
            $teacher = ($female ? $this->dice->pick(DemoData::MOTHERS) : $this->dice->pick(DemoData::FATHERS)).' '.$this->dice->pick(DemoData::SURNAMES);

            [$profile] = app(StaffRecords::class)->hire([
                'name' => $teacher,
                'designation' => 'Teacher',
                'joining_date' => $yearStart->subYears($this->dice->int(0, 6))->toDateString(),
                'gender' => $female ? 'female' : 'male',
                'salary' => $this->dice->int(40, 70) * 1000,
            ], UserType::Teacher, by: $admin);

            if (isset($sections[$i])) {
                $sections[$i]->update(['class_teacher_id' => $profile->id]);
            }
        }

        $records = app(StudentRecords::class);

        for ($i = 0, $count = max(4, (int) round($students * $this->scale)); $i < $count; $i++) {
            $section = $sections[$i % count($sections)];
            $gender = $this->dice->chance(50) ? Gender::Male : Gender::Female;
            $surname = $this->dice->pick(DemoData::SURNAMES);
            $father = $this->dice->pick(DemoData::FATHERS).' '.$surname;

            $records->admit([
                'name' => $this->dice->pick($gender === Gender::Male ? DemoData::BOYS : DemoData::GIRLS).' '.$surname,
                'class_id' => $section->class_id,
                'section_id' => $section->id,
                'gender' => $gender->value,
                'dob' => $yearStart->subYears(4 + $section->schoolClass->numeric_level)->subDays($this->dice->int(0, 360))->toDateString(),
                'admission_date' => $yearStart->subYears($this->dice->int(0, 2))->toDateString(),
            ], [[
                'name' => $father,
                'phone' => '03'.$this->dice->int(0, 4).$this->dice->int(0, 9).'-'.$this->dice->digits(7),
                'relation' => 'father',
                'occupation' => $this->dice->pick(DemoData::OCCUPATIONS),
            ]], $admin);
        }

        auth()->forgetUser();
    }
}
