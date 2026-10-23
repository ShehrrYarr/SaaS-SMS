<?php

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Services\Auth\LoginService;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha', 'name' => 'Alpha Academy']);
    $this->other = makeSchool(['slug' => 'bravo']);
    seedSchoolRoles($this->school, $this->other);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin', [
        'username' => 'head',
        'email' => 'head@alpha.test',
        'phone' => '+15550001',
        'password' => 'secret-123',
    ]);
});

function schoolSignIn(string $login, string $password)
{
    return Volt::test('tenant.auth.login')
        ->set('login', $login)
        ->set('password', $password)
        ->call('authenticate');
}

it('shows the school sign-in page', function () {
    $this->get('/alpha/login')->assertOk()->assertSee('Sign in to')->assertSee('Alpha Academy');
});

it('signs in with an email, username or phone number', function (string $login) {
    tenancy()->set($this->school);

    schoolSignIn($login, 'secret-123')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($this->admin);

    expect($this->admin->fresh()->last_login_at)->not->toBeNull();
})->with(['head@alpha.test', 'head', '+15550001']);

it('rejects a wrong password', function () {
    tenancy()->set($this->school);

    schoolSignIn('head', 'not-the-password')->assertHasErrors('login');

    $this->assertGuest();
});

it('never signs in an account from another school', function () {
    makeSchoolUser($this->other, UserType::Teacher, ['username' => 'visitor', 'password' => 'secret-123']);

    tenancy()->set($this->school);

    schoolSignIn('visitor', 'secret-123')->assertHasErrors('login');

    $this->assertGuest();
});

it('refuses inactive accounts', function () {
    $this->admin->update(['status' => UserStatus::Inactive]);
    tenancy()->set($this->school);

    schoolSignIn('head', 'secret-123')->assertHasErrors('login');

    $this->assertGuest();
});

it('locks the login out after repeated failures', function () {
    tenancy()->set($this->school);

    foreach (range(1, LoginService::MAX_ATTEMPTS) as $attempt) {
        schoolSignIn('head', 'wrong-'.$attempt);
    }

    // Even the right password is refused while locked out.
    schoolSignIn('head', 'secret-123')->assertHasErrors('login');

    $this->assertGuest();
});

it('signs the super admin in to the console', function () {
    $superAdmin = makeSuperAdmin(['email' => 'root@ssms.test', 'password' => 'console-123']);
    tenancy()->enterCentral();

    Volt::test('central.auth.login')
        ->set('login', 'root@ssms.test')
        ->set('password', 'console-123')
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect(route('central.dashboard'));

    $this->assertAuthenticatedAs($superAdmin);
});

it('keeps school accounts out of the console', function () {
    tenancy()->enterCentral();

    Volt::test('central.auth.login')
        ->set('login', 'head@alpha.test')
        ->set('password', 'secret-123')
        ->call('authenticate')
        ->assertHasErrors('login');

    $this->assertGuest();
});
