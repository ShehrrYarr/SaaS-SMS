<?php

use App\Support\Portal;
use Illuminate\Validation\Rules\Password;

use function Livewire\Volt\{layout, rules, state, title};

layout('layouts.guest');
title(__('Change password'));

state(['current_password' => '', 'password' => '', 'password_confirmation' => '']);

rules(fn () => [
    'current_password' => ['required', 'string', 'current_password'],
    'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
]);

$save = function () {
    $this->validate();

    $user = auth()->user();
    $user->forceFill(['password' => $this->password, 'must_change_password' => false])->save();

    session()->regenerate();

    $this->redirect(Portal::homeFor($user) ?? route('tenant.home'));
};

?>

<div>
    <div class="mb-5 text-center">
        <x-brand :name="tenant()->name" :logo="tenant()->logoUrl()" size="lg" compact class="mb-4" />
        <h4 class="fw-normal">{{ __('Choose a new password') }}</h4>
        <p class="text-muted mb-0">
            @if (auth()->user()->must_change_password)
                {{ __('Your administrator gave you a temporary password. Choose your own to continue.') }}
            @else
                {{ __('Enter your current password, then your new one.') }}
            @endif
        </p>
    </div>

    <form wire:submit="save" class="form-custom mt-6" novalidate>
        @foreach ([
            'current_password' => [__('Current password'), 'current-password'],
            'password' => [__('New password'), 'new-password'],
            'password_confirmation' => [__('Confirm new password'), 'new-password'],
        ] as $field => [$label, $autocomplete])
            <div class="mb-5">
                <label class="form-label" for="{{ $field }}">{{ $label }}<span class="text-danger ms-1">*</span></label>
                <input wire:model="{{ $field }}" type="password" id="{{ $field }}" autocomplete="{{ $autocomplete }}"
                       @class(['form-control', 'is-invalid' => $errors->has($field)])>
                @error($field)
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                @if ($field === 'password')
                    <div class="form-text">{{ __('At least 8 characters, with letters and numbers.') }}</div>
                @endif
            </div>
        @endforeach

        <button type="submit" class="btn btn-primary rounded-2 w-100" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">{{ __('Save new password') }}</span>
            <span wire:loading wire:target="save"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>{{ __('Saving…') }}</span>
        </button>

        @unless (auth()->user()->must_change_password)
            <a href="{{ route('tenant.home') }}" class="btn btn-link w-100 mt-2">{{ __('Cancel') }}</a>
        @endunless
    </form>
</div>
