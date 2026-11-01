<?php

use App\Enums\UserType;
use Illuminate\Support\Facades\Auth;

/*
 * Regression: opening a school's page signed the super admin out of the console.
 */

it('keeps the console signed in while a super admin looks at a school as a guest', function () {
    makeSchool(['slug' => 'alpha']);
    $admin = makeSuperAdmin();
    $dashboard = route('central.dashboard');

    $this->withSession([Auth::guard('web')->getName() => $admin->id])->get($dashboard)->assertOk();

    $this->get('/alpha/login')->assertOk()->assertSee('Sign in');
    $this->get('/alpha/admin')->assertRedirect('/alpha/login');

    $this->get($dashboard)->assertOk();
});

it('still signs out an account from another school', function () {
    $alpha = makeSchool(['slug' => 'alpha']);
    makeSchool(['slug' => 'beta']);
    seedSchoolRoles($alpha);
    $user = makeUserWithRole($alpha, UserType::Teacher, 'Teacher');

    $this->actingAs($user)->get('/beta/login')->assertOk();
    $this->assertGuest('web');
});
