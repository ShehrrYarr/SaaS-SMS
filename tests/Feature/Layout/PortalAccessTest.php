<?php

use App\Enums\UserType;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha', 'name' => 'Alpha Academy']);
    seedSchoolRoles($this->school);
});

it('sends guests to their school\'s sign-in page', function () {
    $this->get('/alpha/admin')->assertRedirect(route('tenant.login', ['school' => 'alpha']));
});

it('sends a signed-in user from the school home to their first portal', function (string $role, UserType $type, string $portal) {
    $user = makeUserWithRole($this->school, $type, $role);

    $this->actingAs($user)->get('/alpha')->assertRedirect("/alpha/{$portal}");
})->with([
    'admin' => ['School Admin', UserType::SchoolAdmin, 'admin'],
    'teacher' => ['Teacher', UserType::Teacher, 'teacher'],
    'accountant' => ['Accountant', UserType::Accountant, 'accounting'],
    'student' => ['Student', UserType::Student, 'student'],
]);

it('shows a clear error to a user with no portal yet', function () {
    $this->actingAs(makeSchoolUser($this->school))
        ->get('/alpha')
        ->assertForbidden()
        ->assertSee('has not been given access to any portal');
});

it('keeps users out of portals their roles do not open', function () {
    $teacher = makeUserWithRole($this->school, UserType::Teacher, 'Teacher');

    $this->actingAs($teacher)->get('/alpha/admin')->assertForbidden();
    $this->actingAs($teacher)->get('/alpha/accounting')->assertForbidden();
    $this->actingAs($teacher)->get('/alpha/teacher')->assertOk();
});

it('renders the portal layout with the school brand and an active menu item', function () {
    $admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');

    $this->actingAs($admin)->get('/alpha/admin')
        ->assertOk()
        ->assertSee('Alpha Academy')
        ->assertSee('School setup')
        ->assertSee('aria-current="page"', false);
});

it('offers a portal switcher only to users with several portals', function () {
    $admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    $teacher = makeUserWithRole($this->school, UserType::Teacher, 'Teacher');

    $this->actingAs($admin)->get('/alpha/admin')
        ->assertSee('Switch portal')
        ->assertSee('/alpha/accounting')
        ->assertDontSee('/alpha/student')
        ->assertDontSee('/alpha/parent');

    $this->actingAs($teacher)->get('/alpha/teacher')->assertDontSee('Switch portal');
});

it('opens the console to the super admin only', function () {
    $this->get('/super-admin')->assertRedirect(route('central.login'));

    $this->actingAs(makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin'))
        ->get('/super-admin')
        ->assertForbidden();

    $this->actingAs(makeSuperAdmin())->get('/super-admin')
        ->assertOk()
        ->assertSee('Platform overview')
        ->assertSee('Alpha Academy');
});

it('finds a school from the landing page', function () {
    Volt::test('welcome')->set('school', 'Alpha')->call('go')
        ->assertRedirect(route('tenant.login', ['school' => 'alpha']));

    Volt::test('welcome')->set('school', 'nowhere')->call('go')->assertHasErrors('school');
});

it('does not point suspended schools anywhere', function () {
    $this->school->update(['status' => 'suspended']);

    Volt::test('welcome')->set('school', 'alpha')->call('go')->assertHasErrors('school');

    $this->get('/alpha/login')->assertForbidden()->assertSee('suspended');
});
