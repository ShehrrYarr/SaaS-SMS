<?php

use App\Enums\ExamStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LeaveStatus;
use App\Enums\PayrollStatus;
use App\Enums\UserType;
use App\Models\BookIssue;
use App\Models\Campus;
use App\Models\Examination;
use App\Models\ExamRemark;
use App\Models\Expense;
use App\Models\IncomeLedger;
use App\Models\Invoice;
use App\Models\LeaveRequest;
use App\Models\Mark;
use App\Models\Notice;
use App\Models\Payment;
use App\Models\PayrollRecord;
use App\Models\School;
use App\Models\StudentAttendance;
use App\Models\StudentHostel;
use App\Models\StudentProfile;
use App\Models\StudentTransport;
use App\Models\Timetable;
use App\Models\User;
use App\Notifications\SystemNotification;
use Database\Seeders\Demo\DemoPurger;
use Database\Seeders\Demo\DemoSchools;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => $this->seed(PlanSeeder::class));

afterEach(fn () => Carbon::setTestNow());

it('builds Greenfield Academy through the app\'s own services', function () {
    // Wednesday 21 October, 11 a.m. in Lahore.
    Carbon::setTestNow('2026-10-21 06:00:00');
    $queue = config('queue.default');

    $outcome = (new DemoSchools(scale: 0.1, network: false))->build();

    // The clock, queue and context are put back.
    expect($outcome)->toBe(['greenfield' => 'built'])
        ->and(now()->toDateTimeString())->toBe('2026-10-21 06:00:00')
        ->and(config('queue.default'))->toBe($queue)
        ->and(tenant())->toBeNull();

    $school = School::query()->where('slug', 'greenfield')->firstOrFail();

    tenancy()->run($school, function () use ($school) {
        $user = fn (string $username) => User::query()->where('username', $username)->firstOrFail();
        $student = fn (string $username) => StudentProfile::query()->where('user_id', $user($username)->id)->firstOrFail();

        // Every demo sign-in works with the demo password, without a forced change.
        foreach (['admin', 'sana.malik', 'maryam.iqbal', 'faisal.mahmood', 'ayesha.khan', 'zain.khan', 'imran.khan', 'hina.hr', 'omar.accounts', 'laila.library', 'bilal.transport', 'nadia.hostel', 'sajid.iqbal'] as $username) {
            expect(Hash::check('password', $user($username)->password))->toBeTrue()
                ->and($user($username)->must_change_password)->toBeFalse();
        }

        expect($user('imran.khan')->parentProfile->children()->with('user')->get()->pluck('user.username')->sort()->values()->all())->toBe(['ayesha.khan', 'zain.khan'])
            ->and($user('maryam.iqbal')->staffProfile)->not->toBeNull()
            ->and($user('maryam.iqbal')->parentProfile)->not->toBeNull()
            ->and($user('faisal.mahmood')->hasRole('Exam Coordinator'))->toBeTrue();

        // A full week for every section, no teacher in two rooms at once.
        $clashes = DB::table('timetables')
            ->join('subject_class', 'subject_class.id', '=', 'timetables.subject_class_id')
            ->where('timetables.school_id', $school->id)
            ->select('subject_class.teacher_id', 'timetables.day_of_week', 'timetables.period_number')
            ->groupBy('subject_class.teacher_id', 'timetables.day_of_week', 'timetables.period_number')
            ->havingRaw('count(*) > 1')
            ->count();

        expect(Timetable::query()->count())->toBe(452)->and($clashes)->toBe(0);

        // Registers up to today, never after; Ayesha's leave is waiting for her class teacher.
        expect(StudentAttendance::query()->max('date'))->toStartWith('2026-10-21')
            ->and(StudentAttendance::query()->min('date'))->toStartWith('2026-08-03')
            ->and(LeaveRequest::query()->where('applicant_type', 'student_profile')->where('applicant_id', $student('ayesha.khan')->id)->where('status', LeaveStatus::Pending)->exists())->toBeTrue();

        // Two exams marked and published (with remarks), one on the date sheet, one draft.
        $midTerm = Examination::query()->where('name', 'Mid-Term Examination')->firstOrFail();

        expect($midTerm->status)->toBe(ExamStatus::Published)
            ->and(Mark::query()->whereHas('examSchedule', fn ($query) => $query->where('examination_id', $midTerm->id))->count())->toBeGreaterThan(50)
            ->and(ExamRemark::query()->where('examination_id', $midTerm->id)->where('student_profile_id', $student('ayesha.khan')->id)->exists())->toBeTrue()
            ->and(Examination::query()->pluck('status')->map->value->sort()->values()->all())->toBe(['draft', 'published', 'published', 'scheduled']);

        // Fees: a run each month; the Khans haven't paid October and carry a late fee.
        $ayesha = Invoice::query()->where('student_profile_id', $student('ayesha.khan')->id)->whereNotNull('invoice_batch_id')->orderBy('billing_period')->get();

        expect($ayesha->pluck('billing_period')->all())->toBe(['2026-08', '2026-09', '2026-10'])
            ->and($ayesha->last()->status)->toBe(InvoiceStatus::Overdue)
            ->and((float) $ayesha->last()->late_fee_amount)->toBe(300.0)
            ->and($ayesha->first()->status)->toBe(InvoiceStatus::Paid);

        // Every payment still standing is in the ledger, and nothing else.
        expect(round((float) Payment::query()->whereNull('voided_at')->sum('amount_paid'), 2))
            ->toBe(round((float) IncomeLedger::query()->where('source_type', 'payment')->sum('amount'), 2));

        // Salaries for August and September paid and expensed; October left for HR.
        expect(PayrollRecord::query()->where('status', PayrollStatus::Paid)->pluck('month_year')->unique()->sort()->values()->all())->toBe(['2026-08', '2026-09'])
            ->and(Expense::query()->where('source_type', 'payroll_record')->count())->toBe(PayrollRecord::query()->count());

        // Bus, hostel and library.
        expect(StudentTransport::query()->active()->where('student_profile_id', $student('ayesha.khan')->id)->value('pickup_point'))->toBe('Faisal Town')
            ->and(StudentHostel::query()->active()->exists())->toBeTrue()
            ->and(BookIssue::query()->overdue()->where('user_id', $user('zain.khan')->id)->exists())->toBeTrue();

        // Notices: live, scheduled and draft.
        expect(Notice::query()->whereNull('published_at')->count())->toBe(1)
            ->and(Notice::query()->where('published_at', '>', now())->count())->toBe(1);
    });
});

it('deletes a demo school completely and leaves other schools alone', function () {
    $demo = makeSchool(['slug' => 'greenfield']);
    $other = makeSchool(['slug' => 'riverside']);
    seedSchoolRoles($demo, $other);

    $teachers = [];

    foreach ([$demo, $other] as $school) {
        $teacher = makeUserWithRole($school, UserType::Teacher, 'Teacher');
        tenancy()->run($school, fn () => Campus::query()->create(['name' => 'Main', 'is_main' => true]));
        $teacher->notify(new SystemNotification('Hello'));
        $teachers[$school->slug] = $teacher;
    }

    // Uploads: the demo school's, another school's, and leftovers of a school
    // deleted by an earlier rebuild.
    Storage::fake('public');
    Storage::fake('local');
    Storage::disk('public')->put("schools/{$demo->id}/avatars/a.png", 'x');
    Storage::disk('local')->put("schools/{$demo->id}/notices/1/n.pdf", 'x');
    Storage::disk('public')->put("schools/{$other->id}/avatars/b.png", 'x');
    Storage::disk('local')->put('schools/999999/leave/old.pdf', 'x');

    (new DemoPurger)->purge($demo);

    expect(Storage::disk('public')->exists("schools/{$demo->id}"))->toBeFalse()
        ->and(Storage::disk('local')->exists("schools/{$demo->id}"))->toBeFalse()
        ->and(Storage::disk('local')->exists('schools/999999'))->toBeFalse()
        ->and(Storage::disk('public')->exists("schools/{$other->id}/avatars/b.png"))->toBeTrue();

    expect(DB::table('schools')->where('id', $demo->id)->exists())->toBeFalse()
        ->and(DB::table('users')->where('school_id', $demo->id)->exists())->toBeFalse()
        ->and(DB::table('roles')->where('school_id', $demo->id)->exists())->toBeFalse()
        ->and(DB::table('campuses')->where('school_id', $demo->id)->exists())->toBeFalse()
        ->and(DB::table('notifications')->where('notifiable_id', $teachers['greenfield']->id)->exists())->toBeFalse()
        ->and(DB::table('users')->where('id', $teachers['riverside']->id)->exists())->toBeTrue()
        ->and(DB::table('campuses')->where('school_id', $other->id)->exists())->toBeTrue()
        ->and(DB::table('roles')->where('school_id', $other->id)->exists())->toBeTrue()
        ->and(DB::table('notifications')->where('notifiable_id', $teachers['riverside']->id)->exists())->toBeTrue();
});

it('never deletes a school that is not a demo school', function () {
    $school = makeSchool(['slug' => 'riverside']);

    expect(fn () => (new DemoPurger)->purge($school))->toThrow(InvalidArgumentException::class)
        ->and(School::query()->whereKey($school->id)->exists())->toBeTrue();
});

it('keeps demo schools that are already there unless asked to rebuild them', function () {
    makeSchool(['slug' => 'greenfield']);

    $this->artisan('demo:seed', ['--only-greenfield' => true])
        ->expectsOutputToContain('already there, kept')
        ->expectsOutputToContain('Use --fresh to rebuild')
        ->assertSuccessful();

    $this->artisan('demo:seed', ['--password' => 'short'])->assertFailed();
});
