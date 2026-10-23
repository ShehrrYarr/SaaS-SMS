<?php

/*
 * Give someone in the school a new temporary password.
 *     $dispatch('open-modal', { component: 'forms.reset-user-password', params: { user: 12 } })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\User;
use App\Services\Access\AccessGuard;
use App\Services\Auth\PasswordResetter;
use App\Support\Passwords;
use Illuminate\Validation\Rules\Password;

use function Livewire\Volt\{mount, protect, rules, state, uses};

uses([InteractsWithUi::class]);

state(['user' => null, 'name' => '', 'login' => '', 'done' => false])->locked();

state(['password' => '']);

$account = protect(function () {
    $account = User::query()->findOrFail($this->user);
    abort_unless(app(AccessGuard::class)->canManage(auth()->user(), $account), 403);

    return $account;
});

mount(function (int $user) {
    $this->authorize('users.reset-password');

    $this->user = $user;
    $account = $this->account();

    $this->name = $account->name;
    $this->login = (string) ($account->username ?? $account->email ?? $account->phone);
    $this->password = Passwords::generate();
});

rules(['password' => ['required', 'string', Password::defaults()]]);

$regenerate = fn () => $this->password = Passwords::generate();

$save = function (PasswordResetter $passwords) {
    if ($this->done) {
        $this->closeModal();

        return;
    }

    $this->authorize('users.reset-password');
    $this->validate();

    $passwords->reset($this->account(), $this->password, auth()->user());

    $this->done = true;
    $this->refreshTables();
    $this->toast(__('Password reset for :name.', ['name' => $this->name]));
};

?>

<x-modal.form :title="__('Reset password')" :subtitle="$name" :submit-label="$done ? __('Done') : __('Reset password')">
    @if ($done)
        <x-credentials :url="tenant()->loginUrl()" :username="$login" :password="$password" />
    @else
        <p class="text-muted">{{ __('They will be signed out everywhere and must choose a new password when they next sign in.') }}</p>

        <label class="form-label" for="field-password">{{ __('New temporary password') }}<span class="text-danger ms-1">*</span></label>
        <div class="input-group has-validation">
            <input type="text" id="field-password" wire:model="password" required autocomplete="off" spellcheck="false"
                   @class(['form-control font-monospace', 'is-invalid' => $errors->has('password')])
                   @error('password') aria-invalid="true" aria-describedby="field-password-error" @enderror>
            <button type="button" class="btn btn-light" wire:click="regenerate"><i class="ri-refresh-line me-1"></i>{{ __('Generate') }}</button>
            @error('password')
                <div class="invalid-feedback" id="field-password-error">{{ $message }}</div>
            @enderror
        </div>
    @endif
</x-modal.form>
