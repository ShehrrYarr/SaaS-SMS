<?php

use App\Enums\PayrollStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\PayrollRecord;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Services\Access\AccessGuard;
use App\Services\Staff\StaffRecords;
use App\Tables\ActionFailed;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    tenancy()->set($this->school);

    $this->hr = makeUserWithRole($this->school, UserType::Hr, 'HR');
    $this->hrStaff = StaffProfile::query()->create(['user_id' => $this->hr->id, 'employee_id' => 'H1', 'salary' => 1000]);
    $this->teacher = makeUserWithRole($this->school, UserType::Teacher, 'Teacher');
    $this->teacherStaff = StaffProfile::query()->create(['user_id' => $this->teacher->id, 'employee_id' => 'T1', 'salary' => 1000]);
    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    $this->adminStaff = StaffProfile::query()->create(['user_id' => $this->admin->id, 'employee_id' => 'A1', 'salary' => 1000]);

    $this->actingAs($this->hr);
});

it('lets HR keep a teacher\'s record, including status and job role', function () {
    $this->get("/alpha/hr/staff/{$this->teacherStaff->id}")->assertOk()->assertSee('Edit');
    $this->get("/alpha/hr/staff/{$this->teacherStaff->id}/edit")->assertOk();

    Volt::test('tenant.staff.form', ['staff' => $this->teacherStaff->id])
        ->set('designation', 'Head of Science')
        ->set('salary', '45000')
        ->set('status', UserStatus::Inactive->value)
        ->call('save')
        ->assertHasNoErrors();

    $this->teacherStaff->refresh();

    expect($this->teacherStaff->designation)->toBe('Head of Science')
        ->and((float) $this->teacherStaff->salary)->toBe(45000.0)
        ->and($this->teacher->fresh()->status)->toBe(UserStatus::Inactive)
        ->and($this->teacher->fresh()->hasRole('Teacher'))->toBeTrue();
});

it('gives a teacher added by HR the Teacher role', function () {
    Volt::test('tenant.staff.form')
        ->set('name', 'New Teacher')
        ->set('user_type', UserType::Teacher->value)
        ->call('save')
        ->assertHasNoErrors();

    $added = App\Models\User::query()->where('name', 'New Teacher')->firstOrFail();

    expect($added->hasRole('Teacher'))->toBeTrue();
});

it('keeps HR away from administrators, themselves and passwords', function () {
    $guard = app(AccessGuard::class);

    $this->get("/alpha/hr/staff/{$this->adminStaff->id}/edit")->assertForbidden();
    $this->get("/alpha/hr/staff/{$this->hrStaff->id}/edit")->assertForbidden();

    $adminRole = Role::query()->forCurrentSchool()->where('name', 'School Admin')->firstOrFail();

    expect($guard->canAssignOnStaffRecord($this->hr, $adminRole->load('permissions')))->toBeFalse()
        ->and($guard->canManage($this->hr, $this->teacher))->toBeFalse();

    Volt::test('forms.reset-user-password', ['user' => $this->teacher->id])->assertForbidden();
});

it('refuses to delete someone with salary records, and clears drafts of those without', function () {
    $paid = PayrollRecord::query()->create([
        'staff_profile_id' => $this->teacherStaff->id, 'month_year' => '2026-10', 'status' => PayrollStatus::Paid,
        'basic_salary' => 1000, 'bonuses' => 0, 'deductions' => 0, 'net_salary' => 1000, 'working_days' => 22, 'present_days' => 22,
    ]);

    expect(fn () => app(StaffRecords::class)->delete($this->teacherStaff, $this->admin))->toThrow(ActionFailed::class, 'salary records');
    expect(StaffProfile::query()->find($this->teacherStaff->id))->not->toBeNull();

    $paid->update(['status' => PayrollStatus::Draft]);
    app(StaffRecords::class)->delete($this->teacherStaff, $this->admin);

    expect(StaffProfile::query()->find($this->teacherStaff->id))->toBeNull()
        ->and(PayrollRecord::query()->withTrashed()->where('staff_profile_id', $this->teacherStaff->id)->exists())->toBeFalse();
});
