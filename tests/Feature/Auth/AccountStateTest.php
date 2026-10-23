<?php

use App\Enums\UserStatus;
use App\Enums\UserType;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin', [
        'password' => 'temporary-1',
        'must_change_password' => true,
    ]);
});

it('makes a user with a temporary password choose a new one first', function () {
    $this->actingAs($this->admin)
        ->get('/alpha/admin')
        ->assertRedirect(route('tenant.password.change', ['school' => 'alpha']));

    $this->actingAs($this->admin)->get('/alpha/password/change')->assertOk()->assertSee('Choose a new password');
});

it('clears the flag once a new password is saved', function () {
    $this->actingAs($this->admin);
    tenancy()->set($this->school);

    Volt::test('tenant.auth.change-password')
        ->set('current_password', 'temporary-1')
        ->set('password', 'brand-new-2')
        ->set('password_confirmation', 'brand-new-2')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.dashboard'));

    $admin = $this->admin->fresh();

    expect($admin->must_change_password)->toBeFalse()
        ->and(Hash::check('brand-new-2', $admin->password))->toBeTrue();
});

it('validates the new password', function (string $current, string $new, string $confirm, string $field) {
    $this->actingAs($this->admin);
    tenancy()->set($this->school);

    Volt::test('tenant.auth.change-password')
        ->set('current_password', $current)
        ->set('password', $new)
        ->set('password_confirmation', $confirm)
        ->call('save')
        ->assertHasErrors($field);
})->with([
    'wrong current password' => ['nope', 'brand-new-2', 'brand-new-2', 'current_password'],
    'too short' => ['temporary-1', 'abc1', 'abc1', 'password'],
    'not confirmed' => ['temporary-1', 'brand-new-2', 'brand-new-3', 'password'],
    'same as before' => ['temporary-1', 'temporary-1', 'temporary-1', 'password'],
]);

it('signs out a deactivated account on its next request', function () {
    $this->admin->update(['must_change_password' => false, 'status' => UserStatus::Inactive]);

    $this->actingAs($this->admin)
        ->get('/alpha/admin')
        ->assertRedirect(route('tenant.login', ['school' => 'alpha']));

    $this->assertGuest();
});
