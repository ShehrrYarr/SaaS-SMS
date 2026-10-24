<?php

namespace Database\Seeders\Demo\Greenfield;

use App\Enums\AcademicPeriodStatus;
use App\Enums\ClassroomType;
use App\Enums\PaymentMethod;
use App\Enums\RecordStatus;
use App\Enums\SubjectType;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\Classroom;
use App\Models\Department;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\Billing\SubscriptionManager;
use App\Services\Schools\ProvisionSchool;
use App\Services\Timetable\BellSchedule;
use Carbon\CarbonImmutable;

/**
 * The school record, its settings and calendar, campuses, rooms, classes,
 * sections and subjects.
 */
trait SetsUpSchool
{
    private const SETTINGS = [
        'currency_code' => 'PKR',
        'currency_symbol' => 'Rs',
        'timezone' => 'Asia/Karachi',
        'date_format' => 'd M Y',
        'time_format' => 'h:i A',
        'week_starts_on' => 1,
        'attendance' => ['weekly_off' => '6,7'],
        'numbering' => ['admission_prefix' => 'GFA-', 'employee_prefix' => 'EMP-', 'invoice_prefix' => 'INV-', 'receipt_prefix' => 'RCT-'],
        'payroll' => ['deduct_absences' => true],
        'library' => ['loan_days' => 14, 'max_renewals' => 2, 'max_books_student' => 3, 'max_books_staff' => 5, 'fine_per_day' => 10],
        'fees' => [
            'discounts_enabled' => true,
            'sibling_discount_enabled' => true,
            'bulk_generation_enabled' => true,
            'notify_parents' => true,
            'late_fee' => ['enabled' => true, 'type' => 'fixed', 'amount' => 300, 'grace_days' => 5, 'max_amount' => null],
        ],
    ];

    /**
     * key => [name, code, level, campus, [section => seats]]
     */
    private const CLASSES = [
        'PREP' => ['Prep', 'PREP', 0, 'junior', ['A' => 14, 'B' => 14]],
        'G1' => ['Grade 1', 'G1', 1, 'junior', ['A' => 15, 'B' => 15]],
        'G2' => ['Grade 2', 'G2', 2, 'junior', ['A' => 15, 'B' => 15]],
        'G3' => ['Grade 3', 'G3', 3, 'main', ['A' => 16, 'B' => 16]],
        'G4' => ['Grade 4', 'G4', 4, 'main', ['A' => 14, 'B' => 14]],
        'G5' => ['Grade 5', 'G5', 5, 'main', ['A' => 14, 'B' => 14]],
        'G6' => ['Grade 6', 'G6', 6, 'main', ['A' => 20]],
        'G7' => ['Grade 7', 'G7', 7, 'main', ['A' => 18]],
        'G8' => ['Grade 8', 'G8', 8, 'main', ['A' => 16]],
    ];

    /** Each section's home room. */
    private const HOME_ROOMS = [
        'PREP-A' => 'J-01', 'PREP-B' => 'J-02', 'G1-A' => 'J-03', 'G1-B' => 'J-04', 'G2-A' => 'J-05', 'G2-B' => 'J-06',
        'G3-A' => '101', 'G3-B' => '102', 'G4-A' => '103', 'G4-B' => '104', 'G5-A' => '105',
        'G5-B' => '201', 'G6-A' => '202', 'G7-A' => '203', 'G8-A' => '204',
    ];

    private const SUBJECTS = [
        'ENG' => ['English', SubjectType::Theory, 'Reading, writing, grammar and comprehension.'],
        'URD' => ['Urdu', SubjectType::Theory, 'Urdu language and literature.'],
        'MATH' => ['Mathematics', SubjectType::Theory, null],
        'SCI' => ['General Science', SubjectType::Theory, 'Physics, chemistry and biology basics, with lab work from Grade 6.'],
        'GK' => ['General Knowledge', SubjectType::Theory, 'The world around us, for the youngest classes.'],
        'SST' => ['Social Studies', SubjectType::Theory, 'History, geography and civics of Pakistan and the world.'],
        'ISL' => ['Islamiyat', SubjectType::Theory, null],
        'COMP' => ['Computer Studies', SubjectType::Practical, 'Taught in the computer lab.'],
        'ART' => ['Art & Craft', SubjectType::Optional, null],
    ];

    private const DEPARTMENTS = [
        'junior' => ['Junior School', 'JS', 'junior', 'Prep to Grade 2 at the Junior Campus.'],
        'languages' => ['Languages', 'LANG', 'main', 'English and Urdu.'],
        'maths' => ['Mathematics', 'MATH', 'main', null],
        'sciences' => ['Science & Computing', 'SCI', 'main', 'General science, labs and computer studies.'],
        'humanities' => ['Humanities & Islamiyat', 'HUM', 'main', 'Social studies, Pakistan studies and Islamiyat.'],
        'admin' => ['Administration', 'ADM', 'main', 'Office, HR and accounts.'],
        'support' => ['Support Services', 'SUP', 'main', 'Library, transport and boarding.'],
    ];

    private AcademicYear $lastYear;

    /** @var array<string, Campus> */
    private array $campuses = [];

    /** @var array<string, Department> */
    private array $departments = [];

    /** @var array<string, Classroom> room number => room */
    private array $classrooms = [];

    /** @var array<string, SchoolClass> */
    private array $classes = [];

    /** @var array<string, Section> "G3-A" => section */
    private array $sections = [];

    /** @var array<string, Subject> */
    private array $subjects = [];

    private ?User $superAdmin = null;

    private function createSchool(): void
    {
        $this->superAdmin = tenancy()->runCentral(fn () => User::query()->where('user_type', UserType::SuperAdmin)->orderBy('id')->first());
        $plan = Plan::query()->where('slug', 'premium')->first() ?? Plan::query()->orderByDesc('sort_order')->first();

        $provisioned = app(ProvisionSchool::class)->handle([
            'name' => 'Greenfield Academy',
            'slug' => self::SLUG,
            'email' => 'office@greenfield.test',
            'phone' => '+92 42 3586 4100',
            'address' => '14-C, Main Boulevard, Model Town',
            'city' => 'Lahore',
            'country' => 'Pakistan',
            'plan_id' => $plan?->id,
            'trial_ends_at' => $this->onboarded->addDays($plan?->trial_days ?: 14)->toDateString(),
            'settings' => self::SETTINGS,
        ], [
            'name' => 'Asma Rehman',
            'username' => 'admin',
            'email' => 'principal@greenfield.test',
            'phone' => '0300-4100100',
        ], $this->password, by: $this->superAdmin);

        $this->school = $provisioned->school;
        $this->admin = $provisioned->admin;

        tenancy()->set($this->school);
    }

    private function setUpStructure(): void
    {
        $this->as($this->admin, function () {
            $this->createYears();

            $this->campuses['main'] = tap(Campus::query()->where('is_main', true)->firstOrFail())->update([
                'code' => 'MC',
                'address' => '14-C, Main Boulevard, Model Town, Lahore',
                'phone' => '042-35864100',
                'email' => 'office@greenfield.test',
            ]);

            $this->campuses['junior'] = Campus::query()->create([
                'name' => 'Junior Campus',
                'code' => 'JC',
                'address' => '22-D, Model Town Extension, Lahore',
                'phone' => '042-35864200',
                'email' => 'junior@greenfield.test',
                'is_main' => false,
                'status' => RecordStatus::Active,
            ]);

            foreach (self::DEPARTMENTS as $key => [$name, $code, $campus, $description]) {
                $this->departments[$key] = Department::query()->create([
                    'name' => $name, 'code' => $code, 'campus_id' => $this->campuses[$campus]->id, 'description' => $description,
                ]);
            }

            $this->createRooms();
            $this->createClasses();

            foreach (self::SUBJECTS as $code => [$name, $type, $description]) {
                $this->subjects[$code] = Subject::query()->create(['code' => $code, 'name' => $name, 'type' => $type, 'description' => $description]);
            }

            // 8:00 to 1:00: seven 40-minute periods, a 20-minute break after the fourth.
            app(BellSchedule::class)->save(BellSchedule::generate('08:00', 40, 7, 4, 20));
        });
    }

    private function createYears(): void
    {
        $name = fn (CarbonImmutable $start, CarbonImmutable $end) => $start->year === $end->year ? (string) $start->year : $start->year.'-'.$end->year;
        $lastStart = $this->yearStart->subYear();

        $this->lastYear = AcademicYear::query()->create([
            'name' => $name($lastStart, $this->yearStart->subDay()),
            'start_date' => $lastStart->toDateString(),
            'end_date' => $this->yearStart->subDay()->toDateString(),
            'status' => AcademicPeriodStatus::Archived,
        ]);

        $this->year = AcademicYear::query()->create([
            'name' => $name($this->yearStart, $this->yearEnd),
            'start_date' => $this->yearStart->toDateString(),
            'end_date' => $this->yearEnd->toDateString(),
            'status' => AcademicPeriodStatus::Current,
        ]);

        foreach ([[$this->lastYear, $lastStart, AcademicPeriodStatus::Archived, AcademicPeriodStatus::Archived], [$this->year, $this->yearStart, AcademicPeriodStatus::Current, AcademicPeriodStatus::Upcoming]] as [$year, $start, $first, $second]) {
            $split = $start->addMonthsNoOverflow(5);

            Term::query()->create(['academic_year_id' => $year->id, 'name' => 'First Term', 'start_date' => $start->toDateString(), 'end_date' => $split->subDay()->toDateString(), 'status' => $first, 'sort_order' => 1]);
            Term::query()->create(['academic_year_id' => $year->id, 'name' => 'Second Term', 'start_date' => $split->toDateString(), 'end_date' => $year->end_date->toDateString(), 'status' => $second, 'sort_order' => 2]);
        }
    }

    private function createRooms(): void
    {
        $rooms = [
            // number => [campus, name, building, floor, capacity, type]
            'J-01' => ['junior', 'Butterflies', 'Junior Block', 'Ground', 25, ClassroomType::Classroom],
            'J-02' => ['junior', 'Rainbows', 'Junior Block', 'Ground', 25, ClassroomType::Classroom],
            'J-03' => ['junior', 'Sunflowers', 'Junior Block', 'Ground', 25, ClassroomType::Classroom],
            'J-04' => ['junior', 'Bluebirds', 'Junior Block', 'First', 25, ClassroomType::Classroom],
            'J-05' => ['junior', 'Dolphins', 'Junior Block', 'First', 25, ClassroomType::Classroom],
            'J-06' => ['junior', 'Fireflies', 'Junior Block', 'First', 25, ClassroomType::Classroom],
            'J-ACT' => ['junior', 'Activity Room', 'Junior Block', 'Ground', 40, ClassroomType::Other],
            '101' => ['main', null, 'Main Block', 'Ground', 30, ClassroomType::Classroom],
            '102' => ['main', null, 'Main Block', 'Ground', 30, ClassroomType::Classroom],
            '103' => ['main', null, 'Main Block', 'Ground', 30, ClassroomType::Classroom],
            '104' => ['main', null, 'Main Block', 'Ground', 30, ClassroomType::Classroom],
            '105' => ['main', null, 'Main Block', 'Ground', 30, ClassroomType::Classroom],
            '201' => ['main', null, 'Main Block', 'First', 30, ClassroomType::Classroom],
            '202' => ['main', null, 'Main Block', 'First', 32, ClassroomType::Classroom],
            '203' => ['main', null, 'Main Block', 'First', 32, ClassroomType::Classroom],
            '204' => ['main', null, 'Main Block', 'First', 32, ClassroomType::Classroom],
            'LAB-S' => ['main', 'Science Lab', 'Science Block', 'Ground', 30, ClassroomType::Lab],
            'LAB-C' => ['main', 'Computer Lab', 'Science Block', 'First', 30, ClassroomType::Lab],
            'LIB' => ['main', 'Library', 'Main Block', 'Ground', 40, ClassroomType::Library],
            'HALL' => ['main', 'Auditorium', 'Main Block', 'Ground', 300, ClassroomType::Hall],
        ];

        foreach ($rooms as $number => [$campus, $name, $building, $floor, $capacity, $type]) {
            $this->classrooms[$number] = Classroom::query()->create([
                'campus_id' => $this->campuses[$campus]->id,
                'room_number' => $number,
                'name' => $name,
                'building' => $building,
                'floor' => $floor,
                'capacity' => $capacity,
                'type' => $type,
            ]);
        }
    }

    private function createClasses(): void
    {
        foreach (self::CLASSES as $key => [$name, $code, $level, $campus, $sections]) {
            $seats = $level <= 2 ? 25 : 30;

            $this->classes[$key] = SchoolClass::query()->create([
                'campus_id' => $this->campuses[$campus]->id,
                'name' => $name,
                'code' => $code,
                'numeric_level' => $level,
                'capacity' => $seats * count($sections),
                'sort_order' => $level,
            ]);

            foreach (array_keys($sections) as $section) {
                $this->sections[$key.'-'.$section] = $this->classes[$key]->sections()->create([
                    'name' => $section,
                    'capacity' => $seats,
                    'classroom_id' => $this->classrooms[self::HOME_ROOMS[$key.'-'.$section]]->id,
                ]);
            }
        }
    }

    /**
     * The platform's monthly invoices to the school, paid by bank transfer
     * a few days before each period starts, and far enough ahead that the
     * renewal warning doesn't show.
     */
    private function planSubscription(): void
    {
        $manager = app(SubscriptionManager::class);
        $start = CarbonImmutable::parse($this->school->trial_ends_at->toDateString(), self::TIMEZONE)->addDay();
        $paidUpTo = $this->today->addDays((int) config('plans.subscriptions.warning_days') + 7);
        $number = 1;

        while ($start->lte($paidUpTo)) {
            $end = $start->addMonthNoOverflow()->subDay();
            $paidOn = $start->subDays(3)->setTime(11, 30)->min($this->on($this->today->subDay(), '11:30'));
            $reference = sprintf('GFA-SUB-%04d', $number++);

            $this->at($paidOn, fn () => $manager->recordPayment($this->school->refresh(), [
                'plan_id' => $this->school->plan_id,
                'amount' => $this->school->plan?->price ?? 0,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'paid_on' => $paidOn->toDateString(),
                'payment_method' => PaymentMethod::BankTransfer,
                'reference' => $reference,
            ], $this->superAdmin));

            $start = $end->addDay();
        }
    }
}
