<?php

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Role;
use App\Models\User;
use App\Tables\RolesTable;
use App\Tables\UsersTable;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    $this->other = makeSchool(['slug' => 'bravo']);
    seedSchoolRoles($this->school, $this->other);

    $this->foreignRole = tenancy()->run($this->other, fn () => Role::query()->forCurrentSchool()->where('name', 'Teacher')->firstOrFail());

    tenancy()->set($this->school);
    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    $this->actingAs($this->admin);
});

function schoolRole(string $name): Role
{
    return Role::query()->forCurrentSchool()->where('name', $name)->firstOrFail();
}

it('lists only the school\'s roles', function () {
    $this->get('/alpha/admin/roles')->assertOk()->assertSee('Accountant');

    Volt::test('components.data-table', ['table' => RolesTable::class])
        ->call('runAction', 'delete', $this->foreignRole->id)
        ->assertNotFound();
});

it('creates a role from another one, then opens its permissions', function () {
    Volt::test('forms.role')
        ->set('name', 'Exam Coordinator')
        ->set('copy_from', (string) schoolRole('Teacher')->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $role = schoolRole('Exam Coordinator');

    expect($role->school_id)->toBe($this->school->id)
        ->and($role->is_system)->toBeFalse()
        ->and($role->hasPermissionTo('marks.edit'))->toBeTrue();
});

it('saves the permission grid', function () {
    $role = Role::create(['name' => 'Front Desk', 'guard_name' => 'web']);

    Volt::test('tenant.admin.roles.permissions', ['role' => $role->id])
        ->set('granted', ['portal.admin', 'students.view', 'students.create', 'not.a-permission'])
        ->call('toggleModule', 'notices')
        ->call('save')
        ->assertHasNoErrors();

    expect($role->fresh()->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['notices.create', 'notices.delete', 'notices.edit', 'notices.view', 'portal.admin', 'students.create', 'students.view']);
});

it('never lets an editor grant or remove what they don\'t hold', function () {
    // A deputy who can edit roles but has no payroll access.
    $deputyRole = Role::create(['name' => 'Deputy', 'guard_name' => 'web']);
    $deputyRole->givePermissionTo(['portal.admin', 'roles.view', 'roles.edit', 'students.view', 'students.edit']);
    $this->actingAs(makeUserWithRole($this->school, UserType::Staff, 'Deputy'));

    $target = Role::create(['name' => 'Bursar', 'guard_name' => 'web']);
    $target->givePermissionTo(['payroll.view']);

    Volt::test('tenant.admin.roles.permissions', ['role' => $target->id])
        ->set('granted', ['students.view', 'payroll.generate'])
        ->call('save');

    // students.view added; payroll.generate refused; payroll.view untouched.
    expect($target->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['payroll.view', 'students.view']);
});

it('keeps the School Admin role complete and read-only', function () {
    $role = schoolRole('School Admin');
    $before = $role->permissions()->count();

    Volt::test('tenant.admin.roles.permissions', ['role' => $role->id])
        ->assertSee('always has every permission')
        ->set('granted', [])
        ->call('save')
        ->assertForbidden();

    expect($role->permissions()->count())->toBe($before);
});

it('protects built-in roles and roles in use from deletion', function () {
    $custom = Role::create(['name' => 'Temp', 'guard_name' => 'web']);
    $held = Role::create(['name' => 'Held', 'guard_name' => 'web']);
    makeSchoolUser($this->school)->assignRole($held);

    $table = fn () => Volt::test('components.data-table', ['table' => RolesTable::class]);

    $table()->call('runAction', 'delete', schoolRole('Teacher')->id)->assertForbidden();
    $table()->call('runAction', 'delete', $held->id)->assertDispatched('notify', fn ($n, $p) => $p['type'] === 'error');
    $table()->call('runAction', 'delete', $custom->id)->assertDispatched('notify', type: 'success');

    expect(Role::query()->forCurrentSchool()->where('name', 'Temp')->exists())->toBeFalse();
});

it('cannot open another school\'s role', function () {
    Volt::test('tenant.admin.roles.permissions', ['role' => $this->foreignRole->id])->assertNotFound();
    Volt::test('forms.role', ['role' => $this->foreignRole->id])->assertNotFound();
});

it('gives users roles and changes their status', function () {
    $user = makeSchoolUser($this->school, UserType::Teacher);

    Volt::test('forms.user-access', ['user' => $user->id])
        ->set('roles', [(string) schoolRole('Teacher')->id, (string) schoolRole('Librarian')->id, (string) $this->foreignRole->id])
        ->set('status', UserStatus::Inactive->value)
        ->call('save')
        ->assertHasNoErrors();

    $user = User::query()->find($user->id);

    expect($user->getRoleNames()->sort()->values()->all())->toBe(['Librarian', 'Teacher'])
        ->and($user->status)->toBe(UserStatus::Inactive);
});

it('does not let anyone manage their own account or a more powerful one', function () {
    Volt::test('forms.user-access', ['user' => $this->admin->id])->assertForbidden();

    $deputyRole = Role::create(['name' => 'Deputy', 'guard_name' => 'web']);
    $deputyRole->givePermissionTo(['portal.admin', 'users.view', 'users.edit', 'users.reset-password']);
    $deputy = makeUserWithRole($this->school, UserType::Staff, 'Deputy');
    $this->actingAs($deputy);

    Volt::test('forms.user-access', ['user' => $this->admin->id])->assertForbidden();
    Volt::test('forms.reset-user-password', ['user' => $this->admin->id])->assertForbidden();

    // The table doesn't offer the actions either.
    Volt::test('components.data-table', ['table' => UsersTable::class])
        ->call('runAction', 'reset-password', $this->admin->id)
        ->assertNotFound();
});

it('lets a limited editor give only roles they fully hold', function () {
    $deputyRole = Role::create(['name' => 'Deputy', 'guard_name' => 'web']);
    $deputyRole->givePermissionTo(['portal.admin', 'portal.teacher', 'users.view', 'users.edit', 'library.view', 'library.manage', 'library.issue', 'students.view', 'leave.apply']);
    $this->actingAs(makeUserWithRole($this->school, UserType::Staff, 'Deputy'));

    $user = makeSchoolUser($this->school);

    Volt::test('forms.user-access', ['user' => $user->id])
        ->set('roles', [(string) schoolRole('Librarian')->id, (string) schoolRole('School Admin')->id])
        ->call('save')
        ->assertHasNoErrors();

    expect(User::query()->find($user->id)->getRoleNames()->all())->toBe(['Librarian']);
});

it('resets a password and shows it once', function () {
    $user = makeSchoolUser($this->school, UserType::Teacher, ['username' => 'tina']);

    Volt::test('forms.reset-user-password', ['user' => $user->id])
        ->set('password', 'Fresh-pass-9')
        ->call('save')
        ->assertSet('done', true)
        ->assertSee('Fresh-pass-9')
        ->assertSee('tina');

    $user = User::query()->find($user->id);

    expect(Hash::check('Fresh-pass-9', $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeTrue();
});

it('serves the user and role pages to admins only', function () {
    $this->get('/alpha/admin/users')->assertOk()->assertSee('User accounts');
    $this->get('/alpha/admin/roles/'.schoolRole('Teacher')->id)->assertOk()->assertSee('Can open this portal');

    $this->actingAs(makeUserWithRole($this->school, UserType::Teacher, 'Teacher'))
        ->get('/alpha/admin/users')
        ->assertForbidden();
});
