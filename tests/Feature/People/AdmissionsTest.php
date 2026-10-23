<?php

use App\Enums\EnrollmentStatus;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\ParentProfile;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Access\AccessGuard;
use App\Services\Students\StudentRecords;
use App\Tables\ActionFailed;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    tenancy()->set($this->school);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    $this->year = AcademicYear::factory()->current()->create();
    $campus = Campus::query()->create(['name' => 'Main', 'is_main' => true]);
    $this->class = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 3', 'numeric_level' => 3]);
    $this->a = $this->class->sections()->create(['name' => 'A']);
    $this->b = $this->class->sections()->create(['name' => 'B']);

    $this->actingAs($this->admin);
});

function admit(array $student = [], array $guardians = []): App\Services\Students\Admission
{
    return app(StudentRecords::class)->admit([
        'name' => 'Ali Raza',
        'class_id' => test()->class->id,
        'section_id' => test()->a->id,
        ...$student,
    ], $guardians, test()->admin);
}

it('admits a student with an account, an enrollment and a new parent', function () {
    $admission = admit(['roll_number' => '7'], [['name' => 'Raza Ahmed', 'phone' => '03001234567', 'relation' => 'father']]);
    $student = $admission->student->fresh(['user', 'parents.user', 'enrollments']);

    expect($student->admission_number)->toBe('ADM-0001')
        ->and($student->status)->toBe(StudentStatus::Active)
        ->and($student->user->user_type)->toBe(UserType::Student)
        ->and($student->user->username)->toBe('ali.raza')
        ->and($student->user->must_change_password)->toBeTrue()
        ->and($student->user->hasRole('Student'))->toBeTrue()
        ->and($student->enrollments)->toHaveCount(1)
        ->and($student->enrollments->first()->academic_year_id)->toBe($this->year->id)
        ->and($student->enrollments->first()->section_id)->toBe($this->a->id)
        ->and($student->enrollments->first()->roll_number)->toBe('7')
        ->and($student->parents)->toHaveCount(1)
        ->and($student->parents->first()->pivot->is_primary)->toBeTrue()
        ->and($student->parents->first()->user->hasRole('Parent'))->toBeTrue();

    // Sign-in details for both new accounts, with passwords that work.
    expect($admission->credentials)->toHaveCount(2)
        ->and(Hash::check($admission->credentials[0]->password, $student->user->password))->toBeTrue()
        ->and($admission->credentials[1]->username)->toBe('raza.ahmed');
});

it('numbers admissions in sequence and skips numbers already taken', function () {
    admit(['name' => 'One', 'admission_number' => 'ADM-0002']);

    expect(admit(['name' => 'Two'])->student->admission_number)->toBe('ADM-0001')
        ->and(admit(['name' => 'Three'])->student->admission_number)->toBe('ADM-0003');
});

it('links siblings to the same parent account by phone', function () {
    $first = admit(['name' => 'Sara'], [['name' => 'Bilal', 'phone' => '0300', 'relation' => 'father']]);
    $second = admit(['name' => 'Omar'], [['name' => 'Bilal K', 'phone' => '0300', 'relation' => 'father']]);

    expect(ParentProfile::query()->count())->toBe(1)
        ->and($second->credentials)->toHaveCount(1) // only the student's
        ->and(ParentProfile::query()->first()->children()->count())->toBe(2)
        ->and($first->student->parents()->first()->id)->toBe($second->student->parents()->first()->id);
});

it('makes a staff member a parent on their own account', function () {
    $teacher = makeUserWithRole($this->school, UserType::Teacher, 'Teacher', ['phone' => '0333']);

    $admission = admit([], [['name' => 'Teacher Parent', 'phone' => '0333', 'relation' => 'mother']]);

    $teacher->refresh();
    expect($admission->credentials)->toHaveCount(1)
        ->and($teacher->parentProfile)->not->toBeNull()
        ->and($teacher->hasRole('Parent'))->toBeTrue()
        ->and($teacher->hasRole('Teacher'))->toBeTrue()
        ->and(User::query()->where('phone', '0333')->count())->toBe(1);
});

it('refuses a guardian phone that belongs to a student', function () {
    admit(['name' => 'Kid', 'phone' => '0345']);

    expect(fn () => admit(['name' => 'Other'], [['name' => 'X', 'phone' => '0345', 'relation' => 'guardian']]))
        ->toThrow(ActionFailed::class);

    // Nothing from the failed admission was kept.
    expect(StudentProfile::query()->count())->toBe(1);
});

it('needs a current academic year', function () {
    $this->year->update(['status' => 'upcoming']);

    expect(fn () => admit())->toThrow(ActionFailed::class, 'current academic year');
});

it('stops at the plan\'s student limit', function () {
    $this->school->update(['plan_id' => Plan::factory()->create(['max_students' => 1])->id]);
    tenancy()->set($this->school->fresh());

    admit(['name' => 'First']);

    expect(fn () => admit(['name' => 'Second']))->toThrow(App\Services\Billing\PlanLimitExceeded::class);
});

it('admits through the form and shows everyone\'s sign-in details once', function () {
    Volt::test('tenant.admin.students.form')
        ->set('name', 'Hina Shah')
        ->set('class_id', (string) $this->class->id)
        ->set('section_id', (string) $this->b->id)
        ->set('guardians.0.name', 'Asad Shah')
        ->set('guardians.0.phone', '0311')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('done', true)
        ->assertSee('hina.shah')
        ->assertSee('asad.shah');

    $student = StudentProfile::query()->with('user')->sole();
    expect($student->user->name)->toBe('Hina Shah')->and($student->section_id)->toBe($this->b->id);
});

it('asks for a section when the class has sections', function () {
    Volt::test('tenant.admin.students.form')
        ->set('name', 'No Section')
        ->set('class_id', (string) $this->class->id)
        ->set('guardians', [])
        ->call('save')
        ->assertHasErrors(['section_id' => 'required']);
});

it('rejects a roll number already used in the section', function () {
    admit(['roll_number' => '5']);

    Volt::test('tenant.admin.students.form')
        ->set('name', 'Clash')
        ->set('class_id', (string) $this->class->id)
        ->set('section_id', (string) $this->a->id)
        ->set('roll_number', '5')
        ->set('guardians', [])
        ->call('save')
        ->assertHasErrors('roll_number');
});

it('edits a student and moves their current enrollment with them', function () {
    $student = admit()->student;

    Volt::test('tenant.admin.students.form', ['student' => $student->id])
        ->assertSet('username', 'ali.raza')
        ->set('section_id', (string) $this->b->id)
        ->set('roll_number', '3')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $enrollment = $student->enrollments()->sole();
    expect($student->fresh()->section_id)->toBe($this->b->id)
        ->and($enrollment->section_id)->toBe($this->b->id)
        ->and($enrollment->roll_number)->toBe('3');
});

it('marks a student as left, and back, with their account', function () {
    $student = admit()->student;

    Volt::test('forms.student-status', ['student' => $student->id])
        ->set('status', 'left')
        ->set('remarks', 'Moved away')
        ->call('save')
        ->assertHasNoErrors();

    $student->refresh();
    expect($student->status)->toBe(StudentStatus::Left)
        ->and($student->user->status)->toBe(UserStatus::Inactive)
        ->and($student->enrollments()->sole()->status)->toBe(EnrollmentStatus::Left);

    app(StudentRecords::class)->changeStatus($student, StudentStatus::Active);

    expect($student->fresh()->user->status)->toBe(UserStatus::Active)
        ->and($student->enrollments()->sole()->left_on)->toBeNull();
});

it('deletes a student together with their account', function () {
    $student = admit()->student;

    app(StudentRecords::class)->delete($student, $this->admin);

    expect(StudentProfile::query()->count())->toBe(0)
        ->and(User::query()->whereKey($student->user_id)->exists())->toBeFalse()
        ->and(User::withTrashed()->whereKey($student->user_id)->exists())->toBeTrue();
});

it('lets the school admin manage student and parent accounts', function () {
    $admission = admit([], [['name' => 'P', 'phone' => '0399', 'relation' => 'mother']]);

    $guard = app(AccessGuard::class);
    expect($guard->canManage($this->admin, $admission->student->user))->toBeTrue()
        ->and($guard->canManage($this->admin, $admission->student->parents()->first()->user))->toBeTrue();
});

it('lists, finds and filters students', function () {
    admit(['name' => 'Zara Q', 'admission_number' => 'G-77']);
    admit(['name' => 'Yusuf', 'section_id' => $this->b->id]);

    $this->get(route('admin.students.index'))->assertOk()->assertSee('Students');

    Livewire\Livewire::test('components.data-table', ['table' => App\Tables\StudentsTable::class])
        ->assertSee('Zara Q')
        ->assertSee('Yusuf')
        ->set('search', 'G-77')
        ->assertSee('Zara Q')
        ->assertDontSee('Yusuf')
        ->set('search', '')
        ->set('filters.section_id', (string) $this->b->id)
        ->assertSee('Yusuf')
        ->assertDontSee('Zara Q');
});

it('keeps students to people allowed to see them', function () {
    $teacher = makeUserWithRole($this->school, UserType::Teacher, 'Teacher');
    $student = admit()->student;

    $this->actingAs($teacher);

    $this->get(route('admin.students.index'))->assertForbidden(); // no admin portal
    Volt::test('tenant.admin.students.form', ['student' => $student->id])->assertForbidden();
});
