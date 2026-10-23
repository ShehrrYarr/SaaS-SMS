<?php

use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\Campus;
use App\Models\Department;
use App\Models\ParentProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Staff\StaffRecords;
use App\Services\Students\ParentRecords;
use App\Services\Students\StudentRecords;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    tenancy()->set($this->school);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    AcademicYear::factory()->current()->create();
    $campus = Campus::query()->create(['name' => 'Main', 'is_main' => true]);
    $this->class = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 1']);
    $this->section = $this->class->sections()->create(['name' => 'A']);

    $this->actingAs($this->admin);
});

it('adds a teacher with the Teacher role and an employee ID', function () {
    [$staff, $credentials] = app(StaffRecords::class)->hire(['name' => 'Sana Malik', 'designation' => 'Teacher'], UserType::Teacher, null, $this->admin);

    expect($staff->employee_id)->toBe('EMP-0001')
        ->and((float) $staff->salary)->toBe(0.0)
        ->and($staff->user->hasRole('Teacher'))->toBeTrue()
        ->and($staff->user->must_change_password)->toBeTrue()
        ->and($credentials->username)->toBe('sana.malik');
});

it('adds staff through the form with chosen roles, and edits them', function () {
    $hr = Role::query()->forCurrentSchool()->where('name', 'HR')->value('id');
    $department = Department::query()->create(['name' => 'Science']);

    Volt::test('tenant.staff.form')
        ->assertSet('roles', [(string) Role::query()->forCurrentSchool()->where('name', 'Teacher')->value('id')])
        ->set('name', 'Omar Farooq')
        ->set('user_type', 'hr')
        ->assertSet('roles', [(string) $hr])
        ->set('department_id', (string) $department->id)
        ->set('salary', '55000')
        ->set('bank_account_number', 'PK00BANK1234567890')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('done', true);

    $staff = StaffProfile::query()->with('user')->sole();
    expect($staff->user->user_type)->toBe(UserType::Hr)
        ->and($staff->user->hasRole('HR'))->toBeTrue()
        ->and($staff->department_id)->toBe($department->id)
        ->and($staff->bank_details['account_number'])->toBe('PK00BANK1234567890');

    // Bank details are encrypted at rest.
    expect(DB::table('staff_profiles')->value('bank_details'))->not->toContain('PK00BANK');

    Volt::test('tenant.staff.form', ['staff' => $staff->id])
        ->set('designation', 'HR Officer')
        ->set('bank_account_number', 'PK99NEW')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect($staff->fresh()->designation)->toBe('HR Officer');

    // The change is logged, without the bank details.
    $log = Activity::query()->where('log_name', 'staff_profile')->where('description', 'updated')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and(json_encode($log->properties))->not->toContain('PK99NEW');
});

it('stops at the plan\'s staff limit', function () {
    $this->school->update(['plan_id' => Plan::factory()->create(['max_staff' => 1])->id]);
    tenancy()->set($this->school->fresh());

    app(StaffRecords::class)->hire(['name' => 'One'], UserType::Teacher);

    expect(fn () => app(StaffRecords::class)->hire(['name' => 'Two'], UserType::Teacher))
        ->toThrow(App\Services\Billing\PlanLimitExceeded::class);
});

it('clears a deleted teacher from sections and departments', function () {
    [$staff] = app(StaffRecords::class)->hire(['name' => 'Gone'], UserType::Teacher);
    $this->section->update(['class_teacher_id' => $staff->id]);
    $department = Department::query()->create(['name' => 'Maths', 'head_staff_profile_id' => $staff->id]);

    app(StaffRecords::class)->delete($staff, $this->admin);

    expect($this->section->fresh()->class_teacher_id)->toBeNull()
        ->and($department->fresh()->head_staff_profile_id)->toBeNull()
        ->and(User::query()->whereKey($staff->user_id)->exists())->toBeFalse();
});

it('assigns class teachers and heads of department in their forms', function () {
    [$staff] = app(StaffRecords::class)->hire(['name' => 'Class Head'], UserType::Teacher);

    Volt::test('forms.school-class', ['schoolClass' => $this->class->id])
        ->set('sections.0.class_teacher_id', (string) $staff->id)
        ->call('save')
        ->assertHasNoErrors();

    $department = Department::query()->create(['name' => 'English']);
    Volt::test('forms.department', ['department' => $department->id])
        ->set('head_staff_profile_id', (string) $staff->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->section->fresh()->class_teacher_id)->toBe($staff->id)
        ->and($department->fresh()->head_staff_profile_id)->toBe($staff->id);
});

it('gives HR the staff directory in the HR portal', function () {
    $hr = makeUserWithRole($this->school, UserType::Hr, 'HR');
    [$staff] = app(StaffRecords::class)->hire(['name' => 'Listed'], UserType::Teacher);

    $this->actingAs($hr);

    $this->get(route('hr.staff.index'))->assertOk()->assertSee('Staff');
    $this->get(route('hr.staff.show', $staff->id))->assertOk()->assertSee('Listed');
    $this->get(route('admin.staff.index'))->assertForbidden();
});

it('adds a parent with children and shows their sign-in details', function () {
    $child = app(StudentRecords::class)->admit(['name' => 'Kid', 'class_id' => $this->class->id, 'section_id' => $this->section->id])->student;

    Volt::test('forms.parent')
        ->set('name', 'Nadia Ali')
        ->set('phone', '0321')
        ->call('addChild', $child->id)
        ->set('children.0.relation', 'mother')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('done', true)
        ->assertSee('nadia.ali');

    $parent = ParentProfile::query()->with('children')->sole();
    expect($parent->children->pluck('id')->all())->toBe([$child->id])
        ->and($parent->children->first()->pivot->relation->value)->toBe('mother')
        ->and($parent->children->first()->pivot->is_primary)->toBeTrue();
});

it('removes a parent-only account but keeps a staff parent\'s account', function () {
    $child = app(StudentRecords::class)->admit(['name' => 'Kid', 'class_id' => $this->class->id, 'section_id' => $this->section->id])->student;
    $teacher = makeUserWithRole($this->school, UserType::Teacher, 'Teacher', ['phone' => '0555']);

    [$parentOnly] = app(ParentRecords::class)->create(['name' => 'Only Parent', 'phone' => '0444'], [$child->id => ['relation' => 'father']]);
    [$staffParent] = app(ParentRecords::class)->create(['name' => 'Ignored', 'phone' => '0555'], [$child->id => ['relation' => 'mother']]);

    expect($staffParent->user_id)->toBe($teacher->id);

    app(ParentRecords::class)->delete($parentOnly, $this->admin);
    app(ParentRecords::class)->delete($staffParent, $this->admin);

    $teacher->refresh();
    expect(User::query()->where('phone', '0444')->exists())->toBeFalse()
        ->and($teacher->trashed())->toBeFalse()
        ->and($teacher->hasRole('Parent'))->toBeFalse()
        ->and($teacher->hasRole('Teacher'))->toBeTrue()
        ->and($child->parents()->count())->toBe(0);
});
