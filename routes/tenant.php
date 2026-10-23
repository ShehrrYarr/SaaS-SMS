<?php

use App\Http\Controllers\Tenant\HomeController;
use App\Http\Controllers\Tenant\NoticeAttachmentController;
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

            // The notice board is open to every account, whatever its portal.
            Volt::route('notices', 'tenant.notices.index')->name('tenant.notices.index');
            Volt::route('notices/{notice}', 'tenant.notices.show')->whereNumber('notice')->name('tenant.notices.show');
            Route::get('notices/{notice}/attachment', NoticeAttachmentController::class)->whereNumber('notice')->name('tenant.notices.attachment');

            Route::prefix('admin')->name('admin.')->middleware('portal:admin')->group(function () {
                Volt::route('/', 'tenant.admin.dashboard')->name('dashboard');

                // Academic setup
                Volt::route('academic-years', 'tenant.admin.academic-years.index')->middleware('can:academic-years.view')->name('academic-years.index');
                Volt::route('terms', 'tenant.admin.terms.index')->middleware('can:terms.view')->name('terms.index');
                Volt::route('campuses', 'tenant.admin.campuses.index')->middleware('can:campuses.view')->name('campuses.index');
                Volt::route('departments', 'tenant.admin.departments.index')->middleware('can:departments.view')->name('departments.index');
                Volt::route('classes', 'tenant.admin.classes.index')->middleware('can:classes.view')->name('classes.index');
                Volt::route('classrooms', 'tenant.admin.classrooms.index')->middleware('can:classrooms.view')->name('classrooms.index');
                Volt::route('subjects', 'tenant.admin.subjects.index')->middleware('can:subjects.view')->name('subjects.index');
                Volt::route('subjects/allocation', 'tenant.admin.subjects.allocation')->middleware('can:subjects.allocate')->name('subjects.allocation');

                // Communication
                Volt::route('notices', 'tenant.admin.notices.index')->middleware('can:notices.view')->name('notices.index');

                // Administration
                Volt::route('roles', 'tenant.admin.roles.index')->middleware('can:roles.view')->name('roles.index');
                Volt::route('roles/{role}', 'tenant.admin.roles.permissions')->middleware('can:roles.view')->whereNumber('role')->name('roles.permissions');
                Volt::route('users', 'tenant.admin.users.index')->middleware('can:users.view')->name('users.index');
                Volt::route('activity-log', 'tenant.admin.activity-log.index')->middleware('can:activity-log.view')->name('activity-log.index');
                Volt::route('settings', 'tenant.admin.settings.index')->middleware('can:settings.view')->name('settings.index');
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
