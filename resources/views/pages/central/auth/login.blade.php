<?php

use App\Services\Auth\LoginService;

use function Livewire\Volt\{layout, rules, state, title};

layout('layouts.guest');
title(__('Platform console'));

state(['login' => '', 'password' => '', 'remember' => false]);

rules([
    'login' => ['required', 'string', 'max:255'],
    'password' => ['required', 'string', 'max:255'],
]);

$authenticate = function (LoginService $auth) {
    $this->validate();

    $user = $auth->attempt(null, $this->login, $this->password, $this->remember);

    $this->redirect($auth->redirectPath($user));
};

?>

<div>
    <div class="mb-5 text-center">
        <x-brand :name="config('app.name')" size="lg" compact class="mb-4" />
        <h4 class="fw-normal"><span class="fw-bold text-primary">{{ config('app.name') }}</span> {{ __('Platform Console') }}</h4>
        <p class="text-muted mb-0">{{ __('Sign in with your platform administrator account.') }}</p>
    </div>

    <x-auth.login-form :login-label="__('Email or username')" />
</div>
