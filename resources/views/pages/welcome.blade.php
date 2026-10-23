<?php

use App\Models\School;

use function Livewire\Volt\{layout, rules, state, title};

layout('layouts.guest');
title(__('Find your school'));

state(['school' => '']);

rules(['school' => ['required', 'string', 'max:60']]);

$go = function () {
    $this->validate();

    $slug = strtolower(trim($this->school));
    $school = School::query()->where('slug', $slug)->first();

    if ($school === null || $school->isSuspended()) {
        $this->addError('school', __('We couldn\'t find an active school with that address.'));

        return;
    }

    $this->redirect(route('tenant.login', ['school' => $school->slug]));
};

?>

<div>
    <div class="mb-5 text-center">
        <x-brand :name="config('app.name')" size="lg" compact class="mb-4" />
        <h4 class="fw-normal">{{ __('Welcome to') }} <span class="fw-bold text-primary">{{ config('app.name') }}</span></h4>
        <p class="text-muted mb-0">{{ __('Enter your school\'s address to go to its sign-in page.') }}</p>
    </div>

    <form wire:submit="go" class="form-custom mt-6" novalidate>
        <div class="mb-5">
            <label class="form-label" for="school">{{ __('School address') }}</label>
            <div class="input-group has-validation">
                <span class="input-group-text text-muted">{{ request()->getHost() }}/</span>
                <input wire:model="school" type="text" id="school" placeholder="{{ __('your-school') }}" autocomplete="off" autocapitalize="none" autofocus
                       @class(['form-control', 'is-invalid' => $errors->has('school')])>
                @error('school')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <button type="submit" class="btn btn-primary rounded-2 w-100" wire:loading.attr="disabled" wire:target="go">
            <span wire:loading.remove wire:target="go">{{ __('Continue') }}</span>
            <span wire:loading wire:target="go"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>{{ __('Looking it up…') }}</span>
        </button>

        <p class="mb-0 mt-5 text-muted text-center fs-13">
            {{ __('Platform administrator?') }}
            <a href="{{ route('central.login') }}" class="text-primary fw-medium ms-1">{{ __('Sign in to the console') }}</a>
        </p>
    </form>
</div>
