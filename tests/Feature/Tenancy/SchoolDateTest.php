<?php

use App\Enums\UserType;
use App\Models\School;
use App\Models\StaffProfile;
use App\Services\Attendance\StaffAttendanceRegister;
use App\Services\Attendance\StudentAttendanceRegister;
use Illuminate\Support\Carbon;

/*
 * Regression: "today" must compare correctly with dates at any UTC offset. A
 * register dated today was refused as "future" in every school east of UTC.
 */

function schoolIn(string $timezone): School
{
    $school = makeSchool(['settings' => ['timezone' => $timezone]]);
    seedSchoolRoles($school);

    return $school;
}

dataset('offsets', [
    'Karachi, east of UTC' => ['Asia/Karachi', '2026-11-04 05:00:00', '2026-11-04'],
    'Karachi just after local midnight' => ['Asia/Karachi', '2026-11-03 19:30:00', '2026-11-04'],
    'New York, west of UTC' => ['America/New_York', '2026-11-05 02:00:00', '2026-11-04'],
    'UTC' => ['UTC', '2026-11-04 12:00:00', '2026-11-04'],
]);

it('knows the school\'s date, and that a register dated today is not in the future', function (string $timezone, string $now, string $date) {
    Carbon::setTestNow($now);
    $school = schoolIn($timezone);
    tenancy()->set($school);

    $today = Carbon::parse($date);

    expect($school->today()->toDateString())->toBe($date)
        ->and($today->gt($school->today()))->toBeFalse()
        ->and($today->copy()->addDay()->gt($school->today()))->toBeTrue()
        ->and($today->copy()->subDay()->lt($school->today()))->toBeTrue()
        ->and($today->isSameDay($school->today()))->toBeTrue();

    $admin = makeUserWithRole($school, UserType::SchoolAdmin, 'School Admin');
    $this->actingAs($admin);

    $register = app(StudentAttendanceRegister::class);
    expect($register->canMark($admin, '1-1', $today))->toBeTrue()
        ->and($register->canMark($admin, '1-1', $today->copy()->addDay()))->toBeFalse();

    expect(app(StaffAttendanceRegister::class)->save($today, [], $admin))->toBeArray();
})->with('offsets')->after(fn () => Carbon::setTestNow());

it('lets a class teacher take today\'s register in Pakistan', function () {
    Carbon::setTestNow('2026-11-04 05:00:00');
    $school = schoolIn('Asia/Karachi');
    tenancy()->set($school);

    $teacher = makeUserWithRole($school, UserType::Teacher, 'Teacher');
    $staff = StaffProfile::query()->create(['user_id' => $teacher->id, 'employee_id' => 'T1', 'salary' => 0]);
    $campus = App\Models\Campus::query()->create(['name' => 'Main', 'is_main' => true]);
    $class = App\Models\SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 1', 'numeric_level' => 1]);
    $section = $class->sections()->create(['name' => 'A', 'class_teacher_id' => $staff->id]);
    $this->actingAs($teacher);

    $register = app(StudentAttendanceRegister::class);
    $key = StudentAttendanceRegister::key($class->id, $section->id);

    expect($register->canMark($teacher, $key, Carbon::parse('2026-11-04')))->toBeTrue()
        ->and($register->canMark($teacher, $key, Carbon::parse('2026-11-03')))->toBeFalse();
})->after(fn () => Carbon::setTestNow());
