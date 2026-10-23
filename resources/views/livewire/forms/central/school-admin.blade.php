<?php

/*
 * Add another School Admin account to a school (Super Admin), e.g. when the
 * only administrator has left.
 *     $dispatch('open-modal', { component: 'forms.central.school-admin', params: { school: 3 } })
 */

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\School;
use App\Models\User;
use App\Support\Passwords;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Livewire\Volt\{mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['school' => null, 'schoolName' => '', 'loginUrl' => '', 'done' => false])->locked();

state(['name' => '', 'username' => '', 'email' => '', 'phone' => '', 'password' => '']);

mount(function (int $school) {
    abort_unless(tenancy()->isCentral(), 404);
    $this->authorize('manage-schools');

    $record = School::query()->findOrFail($school);

    $this->school = $record->id;
    $this->schoolName = $record->name;
    $this->loginUrl = $record->loginUrl();
    $this->password = Passwords::generate();
});

rules(fn () => [
    'name' => ['required', 'string', 'max:100'],
    'username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')->where('school_id', $this->school)],
    'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->where('school_id', $this->school)],
    'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->where('school_id', $this->school)],
    'password' => ['required', 'string', Password::defaults()],
]);

$regenerate = fn () => $this->password = Passwords::generate();

$save = function () {
    if ($this->done) {
        $this->closeModal();

        return;
    }

    $this->authorize('manage-schools');
    $data = $this->validate();
    $school = School::query()->findOrFail($this->school);

    $admin = tenancy()->run($school, function () use ($data) {
        $admin = User::query()->create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'password' => $data['password'],
            'user_type' => UserType::SchoolAdmin,
            'status' => UserStatus::Active,
            'must_change_password' => true,
        ]);

        $admin->assignRole(UserType::SchoolAdmin->defaultRole());

        return $admin;
    });

    activity('accounts')->performedOn($school)->causedBy(auth()->user())
        ->withProperties(['admin' => $admin->username])
        ->log('Administrator added');

    $this->done = true;
    $this->refreshTables();
    $this->toast(__(':name can now sign in to :school.', ['name' => $admin->name, 'school' => $this->schoolName]));
};

?>

<x-modal.form :title="__('Add administrator')" :subtitle="$schoolName" :submit-label="$done ? __('Done') : __('Create account')">
    @if ($done)
        <x-credentials :url="$loginUrl" :username="$username" :password="$password" />
    @else
        <div class="row">
            <div class="col-sm-6"><x-form.input name="name" :label="__('Full name')" required maxlength="100" autocomplete="off" /></div>
            <div class="col-sm-6"><x-form.input name="username" :label="__('Username')" required maxlength="60" autocomplete="off" spellcheck="false" /></div>
            <div class="col-sm-6"><x-form.input name="email" type="email" :label="__('Email (optional)')" maxlength="255" autocomplete="off" /></div>
            <div class="col-sm-6"><x-form.input name="phone" type="tel" :label="__('Phone (optional)')" maxlength="30" autocomplete="off" /></div>
        </div>

        <label class="form-label" for="field-password">{{ __('Temporary password') }}<span class="text-danger ms-1">*</span></label>
        <div class="input-group has-validation">
            <input type="text" id="field-password" wire:model="password" required autocomplete="off" spellcheck="false"
                   @class(['form-control font-monospace', 'is-invalid' => $errors->has('password')])
                   @error('password') aria-invalid="true" aria-describedby="field-password-error" @enderror>
            <button type="button" class="btn btn-light" wire:click="regenerate"><i class="ri-refresh-line me-1"></i>{{ __('Generate') }}</button>
            @error('password')
                <div class="invalid-feedback" id="field-password-error">{{ $message }}</div>
            @enderror
        </div>
        <div class="form-text">{{ __('They must choose a new password when they first sign in.') }}</div>
    @endif
</x-modal.form>
