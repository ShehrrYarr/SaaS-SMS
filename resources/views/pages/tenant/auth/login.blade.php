<?php

use App\Services\Auth\LoginService;

use function Livewire\Volt\{layout, rules, state, title};

layout('layouts.guest');
title(__('Sign in'));

state(['login' => '', 'password' => '', 'remember' => false]);

rules([
    'login' => ['required', 'string', 'max:255'],
    'password' => ['required', 'string', 'max:255'],
]);

$authenticate = function (LoginService $auth) {
    $this->validate();

    $user = $auth->attempt(tenant(), $this->login, $this->password, $this->remember);

    // A full page load: the portal uses a different layout from this page.
    $this->redirect($auth->redirectPath($user));
};

?>

<div>
    <div class="mb-5 text-center">
        <x-brand :name="tenant()->name" :logo="tenant()->logoUrl()" size="lg" compact class="mb-4" />
        <h4 class="fw-normal">{{ __('Sign in to') }} <span class="fw-bold text-primary">{{ tenant()->name }}</span></h4>
        <p class="text-muted mb-0">{{ __('Use your email, phone number or username.') }}</p>
    </div>

    <x-auth.login-form>
        <p class="mb-0 mt-5 text-muted text-center fs-13">
            {{ __('Forgot your password? Ask your school administrator to reset it.') }}
        </p>
    </x-auth.login-form>
</div>
