<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
|--------------------------------------------------------------------------
| Super Admin Console  (/super-admin)
|--------------------------------------------------------------------------
|
| Runs in the central context: tenant scoping is lifted so the console can
| work across every school.
|
*/

Route::prefix(config('tenancy.central_prefix'))
    ->name('central.')
    ->middleware('central')
    ->group(function () {
        Route::middleware('guest')->group(function () {
            Volt::route('login', 'central.auth.login')->name('login');
        });

        Route::middleware(['auth', 'super-admin'])->group(function () {
            Volt::route('/', 'central.dashboard')->name('dashboard');
        });
    });
