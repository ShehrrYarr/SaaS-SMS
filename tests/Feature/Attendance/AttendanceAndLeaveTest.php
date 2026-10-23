<?php

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\StaffAttendanceStatus;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\Campus;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\SchoolClass;
use App\Models\StaffAttendance;
use App\Models\StudentAttendance;
use App\Models\SubjectClass;
use App\Services\Attendance\SchoolCalendar;
use App\Services\Attendance\StudentAttendanceRegister;
use App\Services\Leave\StudentLeave;
use App\Services\Schools\SeedSchoolDefaults;
use App\Services\Students\StudentRecords;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;

beforeEach(function () {
    // A Wednesday, so "yesterday" and "today" are school days.
    Carbon::setTestNow('2026-10-21 09:00:00');

    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    app(SeedSchoolDefaults::class)->handle($this->school);
    tenancy()->set($this->school);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    $this->teacherUser = makeUserWithRole($this->school, UserType::Teacher, 'Teacher');
    $this->teacher = makeStaffProfile($this->school, $this->teacherUser);
    $this->otherTeacherUser = makeUserWithRole($this->school, UserType::Teacher, 'Teacher');
    makeStaffProfile($this->school, $this->otherTeacherUser);

    $this->year = AcademicYear::factory()->current()->create(['start_date' => '2026-08-01', 'end_date' => '2027-06-30']);
    $campus = Campus::query()->create(['name' => 'Main', 'is_main' => true]);
    $this->class = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 4']);
    $this->a = $this->class->sections()->create(['name' => 'A', 'class_teacher_id' => $this->teacher->id]);
    $this->b = $this->class->sections()->create(['name' => 'B']);

    $records = app(StudentRecords::class);
    $this->ali = $records->admit(['name' => 'Ali', 'class_id' => $this->class->id, 'section_id' => $this->a->id, 'roll_number' => '2'])->student;
    $this->bina = $records->admit(['name' => 'Bina', 'class_id' => $this->class->id, 'section_id' => $this->a->id, 'roll_number' => '1'],
        [['name' => 'Bina Mum', 'phone' => '0301', 'relation' => 'mother']])->student;
    $this->cyrus = $records->admit(['name' => 'Cyrus', 'class_id' => $this->class->id, 'section_id' => $this->b->id])->student;

    $this->keyA = StudentAttendanceRegister::key($this->class->id, $this->a->id);
    $this->keyB = StudentAttendanceRegister::key($this->class->id, $this->b->id);
});

afterEach(fn () => Carbon::setTestNow());

it('gives teachers their own sections and managers every section', function () {
    $register = app(StudentAttendanceRegister::class);

    expect($register->groupsFor($this->teacherUser)->keys()->all())->toBe([$this->keyA])
        ->and($register->groupsFor($this->admin)->keys()->all())->toBe([$this->keyA, $this->keyB]);

    // A subject allocation also counts.
    SubjectClass::query()->create(['academic_year_id' => $this->year->id, 'class_id' => $this->class->id, 'section_id' => $this->b->id,
        'subject_id' => App\Models\Subject::query()->create(['code' => 'ENG', 'name' => 'English'])->id, 'teacher_id' => $this->teacher->id]);

    expect($register->groupsFor($this->teacherUser)->keys()->all())->toBe([$this->keyA, $this->keyB]);
});

it('lets a teacher take today\'s register for their section, in roll order', function () {
    $this->actingAs($this->teacherUser);

    Volt::test('attendance.student-register', ['groupKey' => $this->keyA, 'date' => '2026-10-21'])
        ->assertSeeInOrder(['Bina', 'Ali'])
        ->assertSet("entries.{$this->ali->id}.status", 'present')
        ->set("entries.{$this->ali->id}.status", 'absent')
        ->set("entries.{$this->ali->id}.remarks", 'No note')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('attendance-saved');

    $ali = StudentAttendance::query()->where('student_profile_id', $this->ali->id)->sole();
    expect($ali->status)->toBe(AttendanceStatus::Absent)
        ->and($ali->remarks)->toBe('No note')
        ->and($ali->academic_year_id)->toBe($this->year->id)
        ->and($ali->marked_by)->toBe($this->teacherUser->id)
        ->and(StudentAttendance::query()->count())->toBe(2);

    // One summary in the activity log, not a row per student.
    expect(Activity::query()->where('log_name', 'student_attendance')->count())->toBe(1);
});

it('keeps teachers to today and to their own sections', function () {
    $this->actingAs($this->teacherUser);

    Volt::test('attendance.student-register', ['groupKey' => $this->keyA, 'date' => '2026-10-20'])
        ->assertSet('canMark', false)
        ->call('save')
        ->assertForbidden();

    Volt::test('attendance.student-register', ['groupKey' => $this->keyB, 'date' => '2026-10-21'])->assertForbidden();

    $this->get(route('teacher.attendance.index'))->assertOk();
});

it('lets attendance managers correct a past day, and logs the correction', function () {
    $register = app(StudentAttendanceRegister::class);
    $register->save($this->class, $this->a, Carbon::parse('2026-10-20'), [$this->ali->id => ['status' => 'absent']], $this->admin);

    $this->actingAs($this->admin);

    Volt::test('attendance.student-register', ['groupKey' => $this->keyA, 'date' => '2026-10-20'])
        ->assertSet('canMark', true)
        ->assertSet("entries.{$this->ali->id}.status", 'absent')
        ->set("entries.{$this->ali->id}.status", 'late')
        ->call('save');

    expect(StudentAttendance::query()->where('student_profile_id', $this->ali->id)->value('status'))->toBe(AttendanceStatus::Late)
        ->and(Activity::query()->where('log_name', 'student_attendance')->where('description', 'updated')->count())->toBe(1);
});

it('never takes attendance for a future date', function () {
    expect(fn () => app(StudentAttendanceRegister::class)->save($this->class, $this->a, Carbon::parse('2026-10-22'), [], $this->admin))
        ->toThrow(ActionFailed::class);
});

it('counts school days without the weekly off day', function () {
    $calendar = app(SchoolCalendar::class);

    // Default weekly off: Sunday. 19 Oct 2026 is a Monday.
    expect($calendar->countSchoolDays(Carbon::parse('2026-10-19'), Carbon::parse('2026-10-25')))->toBe(6)
        ->and($calendar->isWeeklyOff(Carbon::parse('2026-10-25')))->toBeTrue();

    $this->school->update(['settings' => ['attendance' => ['weekly_off' => '6,7']]]);
    tenancy()->set($this->school->fresh());

    expect(app(SchoolCalendar::class)->countSchoolDays(Carbon::parse('2026-10-19'), Carbon::parse('2026-10-25')))->toBe(5);
});

it('runs parent leave through the class teacher and excuses the register', function () {
    $parent = $this->bina->parents()->with('user')->first()->user;
    $sick = LeaveType::query()->where('name', 'Sick Leave')->sole();

    // Already marked absent yesterday; the leave covers yesterday and today.
    app(StudentAttendanceRegister::class)->save($this->class, $this->a, Carbon::parse('2026-10-20'), [$this->bina->id => ['status' => 'absent']], $this->teacherUser);

    $this->actingAs($parent);
    Volt::test('forms.student-leave', ['as' => 'parent'])
        ->assertSet('student_id', (string) $this->bina->id)
        ->set('leave_type_id', (string) $sick->id)
        ->set('start_date', '2026-10-20')
        ->set('end_date', '2026-10-21')
        ->set('reason', 'Flu')
        ->call('save')
        ->assertHasNoErrors();

    $request = LeaveRequest::query()->sole();
    expect($request->status)->toBe(LeaveStatus::Pending)
        ->and((float) $request->days)->toBe(2.0)
        ->and($this->teacherUser->notifications()->count())->toBe(1);

    // Another teacher can't decide it; the class teacher can.
    $leave = app(StudentLeave::class);
    expect($leave->canReview($this->otherTeacherUser, $request))->toBeFalse()
        ->and($leave->canReview($this->teacherUser, $request))->toBeTrue();

    $this->actingAs($this->teacherUser);
    Volt::test('forms.leave-review', ['request' => $request->id, 'decision' => 'approve'])
        ->set('remarks', 'Get well soon')
        ->call('save')
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe(LeaveStatus::Approved)
        ->and(StudentAttendance::query()->where('student_profile_id', $this->bina->id)->value('status'))->toBe(AttendanceStatus::Excused)
        ->and($parent->notifications()->count())->toBe(1);

    // Today's register starts her as excused.
    Volt::test('attendance.student-register', ['groupKey' => $this->keyA, 'date' => '2026-10-21'])
        ->assertSet("entries.{$this->bina->id}.status", 'excused')
        ->assertSee('On leave');
});

it('refuses overlapping leave and lets parents cancel pending requests', function () {
    $parent = $this->bina->parents()->with('user')->first()->user;
    $type = LeaveType::query()->where('name', 'Family Emergency')->sole();
    $leave = app(StudentLeave::class);

    $request = $leave->apply($this->bina, $type, Carbon::parse('2026-10-22'), Carbon::parse('2026-10-23'), 'Wedding', $parent);

    expect(fn () => $leave->apply($this->bina, $type, Carbon::parse('2026-10-23'), Carbon::parse('2026-10-24'), 'Again', $parent))
        ->toThrow(ActionFailed::class, 'already');

    // Staff-only leave types aren't offered for students.
    expect(fn () => $leave->apply($this->bina, LeaveType::query()->where('name', 'Casual Leave')->sole(), Carbon::parse('2026-10-26'), Carbon::parse('2026-10-26'), 'X', $parent))
        ->toThrow(ActionFailed::class);

    // New accounts must change their password before using a portal.
    $parent->forceFill(['must_change_password' => false])->save();

    $this->actingAs($parent);
    $this->get(route('parent.leave.index'))->assertOk();

    Livewire\Livewire::test('components.data-table', ['table' => App\Tables\StudentLeaveTable::class, 'params' => ['scope' => 'parent']])
        ->assertSee('Wedding')
        ->call('runAction', 'cancel', $request->id);

    expect($request->fresh()->status)->toBe(LeaveStatus::Cancelled);
});

it('shows each parent only their own children\'s leave', function () {
    $type = LeaveType::query()->where('name', 'Sick Leave')->sole();
    $leave = app(StudentLeave::class);
    $leave->apply($this->cyrus, $type, Carbon::parse('2026-10-22'), Carbon::parse('2026-10-22'), 'Cyrus is ill', $this->admin);

    $this->actingAs($this->bina->parents()->with('user')->first()->user);

    Livewire\Livewire::test('components.data-table', ['table' => App\Tables\StudentLeaveTable::class, 'params' => ['scope' => 'parent']])
        ->assertDontSee('Cyrus is ill');
});

it('keeps the HR staff register', function () {
    $hr = makeUserWithRole($this->school, UserType::Hr, 'HR');
    $this->actingAs($hr);

    Volt::test('tenant.hr.attendance.index')
        ->set("entries.{$this->teacher->id}.status", 'late')
        ->set("entries.{$this->teacher->id}.check_in", '09:20')
        ->call('save')
        ->assertHasNoErrors();

    $record = StaffAttendance::query()->where('staff_profile_id', $this->teacher->id)->sole();
    expect($record->status)->toBe(StaffAttendanceStatus::Late)
        ->and($record->check_in)->toBe('09:20:00');
});

it('builds the monthly attendance sheet', function () {
    app(StudentAttendanceRegister::class)->save($this->class, $this->a, Carbon::parse('2026-10-20'), [
        $this->ali->id => ['status' => 'present'],
        $this->bina->id => ['status' => 'absent'],
    ], $this->admin);

    $this->actingAs($this->admin);

    Volt::test('tenant.attendance.report')
        ->set('group', $this->keyA)
        ->set('month', '2026-10')
        ->assertSee('Grade 4 · A')
        ->assertSee('100%')
        ->assertSee('0%');
});
