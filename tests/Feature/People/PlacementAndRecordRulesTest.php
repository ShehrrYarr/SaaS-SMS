<?php

use App\Enums\StudentStatus;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\SchoolClass;
use App\Models\StaffAttendance;
use App\Models\StaffProfile;
use App\Rules\PhoneNumber;
use App\Services\Attendance\StaffAttendanceRegister;
use App\Services\Imports\StudentImport;
use App\Services\People\Accounts;
use App\Services\Students\StudentRecords;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    tenancy()->set($this->school);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    AcademicYear::factory()->current()->create(['name' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30']);
    $campus = Campus::query()->create(['name' => 'Main', 'is_main' => true]);
    $this->class = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 1', 'numeric_level' => 1]);
    $this->section = $this->class->sections()->create(['name' => 'A', 'capacity' => 1]);

    $this->actingAs($this->admin);
});

it('refuses a new student once the section is full, and frees the seat when one leaves', function () {
    $first = app(StudentRecords::class)->admit(['name' => 'First', 'class_id' => $this->class->id, 'section_id' => $this->section->id])->student;

    $form = fn () => Volt::test('tenant.admin.students.form')
        ->set('name', 'Second')
        ->set('class_id', (string) $this->class->id)
        ->set('section_id', (string) $this->section->id)
        ->set('guardians', [])
        ->call('save');

    $form()->assertHasErrors('class_id');

    app(StudentRecords::class)->changeStatus($first, StudentStatus::Left);

    $form()->assertHasNoErrors();
});

it('lets a full section\'s students keep being edited', function () {
    $first = app(StudentRecords::class)->admit(['name' => 'First', 'class_id' => $this->class->id, 'section_id' => $this->section->id])->student;

    Volt::test('tenant.admin.students.form', ['student' => $first->id])
        ->set('address', '12 Garden Road')
        ->call('save')
        ->assertHasNoErrors();
});

it('holds a CSV import to the same seat and roll number rules as the form', function () {
    $this->section->update(['capacity' => 2]);
    app(StudentRecords::class)->admit(['name' => 'First', 'class_id' => $this->class->id, 'section_id' => $this->section->id, 'roll_number' => '1']);

    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, "Name,Class,Section,Roll Number,Phone\nSame Roll,Grade 1,A,1,\nFree Seat,Grade 1,A,2,\nNo Seat,Grade 1,A,3,\nBad Phone,Grade 2,,,abc\n");

    $rows = collect(app(StudentImport::class)->check($path)['rows'])->keyBy('line');

    expect(implode(' ', $rows[2]['errors']))->toContain('roll number 1')
        ->and($rows[3]['errors'])->toBe([])
        ->and(implode(' ', $rows[4]['errors']))->toContain('is full')
        ->and(implode(' ', $rows[5]['errors']))->toContain('phone number');
});

it('rejects admission dates in the future or the distant past', function (string $date) {
    Volt::test('tenant.admin.students.form')
        ->set('name', 'Dated')
        ->set('admission_date', $date)
        ->call('save')
        ->assertHasErrors('admission_date');
})->with(['2099-01-01', '1900-01-01']);

it('gives a short name a username long enough to save later', function () {
    expect(app(Accounts::class)->uniqueUsername('S2'))->toBe('s2.user')
        ->and(strlen(app(Accounts::class)->uniqueUsername('Al')))->toBeGreaterThanOrEqual(3)
        ->and(app(Accounts::class)->uniqueUsername('Sara Khan'))->toBe('sara.khan');
});

it('recognises phone numbers', function () {
    foreach (['03001234567', '+92 300 1234567', '(042) 111-222', '0300'] as $good) {
        expect(PhoneNumber::isValid($good))->toBeTrue();
    }

    foreach (['abc', '12', '+', '0300-CALL-ME'] as $bad) {
        expect(PhoneNumber::isValid($bad))->toBeFalse();
    }
});

it('keeps the staff register honest about times and joining dates', function () {
    $user = makeUserWithRole($this->school, UserType::Teacher, 'Teacher', ['name' => 'Nadia']);
    $staff = StaffProfile::query()->create(['user_id' => $user->id, 'employee_id' => 'T1', 'salary' => 1000, 'joining_date' => '2026-10-15']);
    $register = app(StaffAttendanceRegister::class);

    expect($register->roster(null, Carbon::parse('2026-10-14'))->pluck('id')->all())->not->toContain($staff->id)
        ->and($register->roster(null, Carbon::parse('2026-10-15'))->pluck('id')->all())->toContain($staff->id);

    $day = Carbon::parse('2026-10-20');

    expect(fn () => $register->save($day, [$staff->id => ['status' => 'present', 'check_in' => '10:00', 'check_out' => '09:00']], $this->admin))
        ->toThrow(ActionFailed::class, 'check-out');

    $register->save($day, [$staff->id => ['status' => 'present', 'check_in' => '09:00', 'check_out' => '15:00']], $this->admin);

    expect(StaffAttendance::query()->where('staff_profile_id', $staff->id)->count())->toBe(1);
});
