<?php

use App\Http\Controllers\Tenant\HomeController;
use App\Http\Controllers\Tenant\LeaveAttachmentController;
use App\Http\Controllers\Tenant\NoticeAttachmentController;
use App\Http\Controllers\Tenant\ReportCardController;
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
            // Staff screens live in both the School Admin and HR portals.
            $staffRoutes = function () {
                Volt::route('staff', 'tenant.staff.index')->middleware('can:staff.view')->name('staff.index');
                Volt::route('staff/create', 'tenant.staff.form')->middleware('can:staff.create')->name('staff.create');
                Volt::route('staff/import', 'tenant.staff.import')->middleware('can:staff.import')->name('staff.import');
                Volt::route('staff/{staff}', 'tenant.staff.show')->middleware('can:staff.view')->whereNumber('staff')->name('staff.show');
                Volt::route('staff/{staff}/edit', 'tenant.staff.form')->middleware('can:staff.edit')->whereNumber('staff')->name('staff.edit');
            };

            Route::get('/', HomeController::class)->name('tenant.home');

            Volt::route('password/change', 'tenant.auth.change-password')->name('tenant.password.change');

            // The notice board is open to every account, whatever its portal.
            Volt::route('notices', 'tenant.notices.index')->name('tenant.notices.index');
            Volt::route('notices/{notice}', 'tenant.notices.show')->whereNumber('notice')->name('tenant.notices.show');
            Route::get('notices/{notice}/attachment', NoticeAttachmentController::class)->whereNumber('notice')->name('tenant.notices.attachment');
            Route::get('leave/{leaveRequest}/attachment', LeaveAttachmentController::class)->whereNumber('leaveRequest')->name('tenant.leave.attachment');

            Route::prefix('admin')->name('admin.')->middleware('portal:admin')->group(function () use ($staffRoutes) {
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

                // Examinations
                Volt::route('examinations', 'tenant.admin.examinations.index')->middleware('can:examinations.view')->name('examinations.index');
                Volt::route('examinations/{examination}', 'tenant.admin.examinations.show')->middleware('can:examinations.view')->whereNumber('examination')->name('examinations.show');
                Route::middleware('can:examinations.view')->whereNumber(['examination', 'student'])->controller(ReportCardController::class)->group(function () {
                    Route::get('examinations/{examination}/report-cards', 'section')->name('examinations.report-cards');
                    Route::get('examinations/{examination}/report-cards/{student}', 'show')->name('examinations.report-card');
                    Route::get('examinations/{examination}/report-cards/{student}/pdf', 'pdf')->name('examinations.report-card.pdf');
                });
                Volt::route('grade-scales', 'tenant.admin.grade-scales.index')->middleware('can:grade-scales.view')->name('grade-scales.index');

                // People
                Volt::route('students', 'tenant.admin.students.index')->middleware('can:students.view')->name('students.index');
                Volt::route('students/admit', 'tenant.admin.students.form')->middleware('can:students.create')->name('students.create');
                Volt::route('students/import', 'tenant.admin.students.import')->middleware('can:students.import')->name('students.import');
                Volt::route('students/promote', 'tenant.admin.students.promote')->middleware('can:students.promote')->name('students.promote');
                Volt::route('students/{student}', 'tenant.admin.students.show')->middleware('can:students.view')->whereNumber('student')->name('students.show');
                Volt::route('students/{student}/edit', 'tenant.admin.students.form')->middleware('can:students.edit')->whereNumber('student')->name('students.edit');
                Volt::route('parents', 'tenant.admin.parents.index')->middleware('can:parents.view')->name('parents.index');
                $staffRoutes();
                Volt::route('attendance', 'tenant.admin.attendance.index')->middleware('can:attendance.manage')->name('attendance.index');
                Volt::route('attendance/report', 'tenant.attendance.report')->middleware('can:attendance.view')->name('attendance.report');

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
                Volt::route('classes', 'tenant.teacher.classes.index')->name('classes.index');
                Volt::route('attendance', 'tenant.teacher.attendance.index')->middleware('can:attendance.mark')->name('attendance.index');
                Volt::route('attendance/report', 'tenant.attendance.report')->middleware('can:attendance.view')->name('attendance.report');
                Volt::route('student-leave', 'tenant.teacher.student-leave.index')->middleware('can:student-leave.view')->name('student-leave.index');
                Volt::route('gradebook', 'tenant.teacher.gradebook.index')->middleware('can:marks.edit')->name('gradebook.index');
            });

            Route::prefix('hr')->name('hr.')->middleware('portal:hr')->group(function () use ($staffRoutes) {
                Volt::route('/', 'tenant.hr.dashboard')->name('dashboard');
                $staffRoutes();
                Volt::route('attendance', 'tenant.hr.attendance.index')->middleware('can:staff-attendance.view')->name('attendance.index');
                Volt::route('leave-types', 'tenant.hr.leave-types.index')->middleware('can:leave.manage')->name('leave-types.index');
            });

            Route::prefix('accounting')->name('accounting.')->middleware('portal:accounting')->group(function () {
                Volt::route('/', 'tenant.accounting.dashboard')->name('dashboard');
            });

            Route::prefix('student')->name('student.')->middleware('portal:student')->group(function () {
                Volt::route('/', 'tenant.student.dashboard')->name('dashboard');
            });

            Route::prefix('parent')->name('parent.')->middleware('portal:parent')->group(function () {
                Volt::route('/', 'tenant.parent.dashboard')->name('dashboard');
                Volt::route('leave', 'tenant.parent.leave.index')->name('leave.index');
            });
        });
    });
