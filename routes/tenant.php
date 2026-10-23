<?php

use App\Http\Controllers\Tenant\HomeController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
|--------------------------------------------------------------------------
| School Routes  (/{school}/...)
|--------------------------------------------------------------------------
|
| `tenant` resolves the school from the first URL segment and makes it the
| active tenant; every route below runs inside that school. Route names never
| include the school: route('admin.dashboard') fills {school} in from the
| active tenant.
|
*/

Route::prefix('{'.config('tenancy.route_parameter').'}')
    ->middleware('tenant')
    ->group(function () {
        Route::middleware('guest')->group(function () {
            Volt::route('login', 'tenant.auth.login')->name('tenant.login');
        });

        Route::middleware(['auth', 'account'])->group(function () {
            Route::get('/', HomeController::class)->name('tenant.home');

            Volt::route('password/change', 'tenant.auth.change-password')->name('tenant.password.change');

            Route::prefix('admin')->name('admin.')->middleware('portal:admin')->group(function () {
                Volt::route('/', 'tenant.admin.dashboard')->name('dashboard');

                Volt::route('academic-years', 'tenant.admin.academic-years.index')
                    ->middleware('can:academic-years.view')
                    ->name('academic-years.index');
            });

            Route::prefix('teacher')->name('teacher.')->middleware('portal:teacher')->group(function () {
                Volt::route('/', 'tenant.teacher.dashboard')->name('dashboard');
            });

            Route::prefix('hr')->name('hr.')->middleware('portal:hr')->group(function () {
                Volt::route('/', 'tenant.hr.dashboard')->name('dashboard');
            });

            Route::prefix('accounting')->name('accounting.')->middleware('portal:accounting')->group(function () {
                Volt::route('/', 'tenant.accounting.dashboard')->name('dashboard');
            });

            Route::prefix('student')->name('student.')->middleware('portal:student')->group(function () {
                Volt::route('/', 'tenant.student.dashboard')->name('dashboard');
            });

            Route::prefix('parent')->name('parent.')->middleware('portal:parent')->group(function () {
                Volt::route('/', 'tenant.parent.dashboard')->name('dashboard');
            });
        });
    });
