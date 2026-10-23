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

            Route::middleware('can:manage-schools')->group(function () {
                Volt::route('schools', 'central.schools.index')->name('schools.index');
                Volt::route('schools/create', 'central.schools.create')->name('schools.create');
                Volt::route('schools/{school}', 'central.schools.show')->name('schools.show');
            });

            Route::middleware('can:manage-subscriptions')->group(function () {
                Volt::route('plans', 'central.plans.index')->name('plans.index');
                Volt::route('subscriptions', 'central.subscriptions.index')->name('subscriptions.index');
            });

            Volt::route('analytics', 'central.analytics')->middleware('can:view-analytics')->name('analytics');
        });
    });
