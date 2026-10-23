<?php

use App\Enums\UserType;
use App\Models\School;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against MySQL (ssms_testing, see phpunit.xml) so foreign
| keys, unique indexes and JSON columns behave exactly as in production.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function makeSchool(array $attributes = []): School
{
    return School::factory()->create($attributes);
}

/**
 * Create a user belonging to $school (created inside that school's context).
 *
 * Users are reloaded so database defaults are present: actingAs() marks the
 * model as "retrieved", and strict mode then rejects any column it never read.
 */
function makeSchoolUser(School $school, UserType $type = UserType::Staff, array $attributes = []): User
{
    return tenancy()->run($school, fn () => User::factory()->type($type)->create($attributes)->refresh());
}

function makeSuperAdmin(array $attributes = []): User
{
    return tenancy()->runCentral(fn () => User::factory()->superAdmin()->create($attributes)->refresh());
}

/**
 * Seed the permission catalog and give $school its built-in roles.
 */
function seedSchoolRoles(School ...$schools): void
{
    test()->seed(Database\Seeders\PermissionCatalogSeeder::class);

    foreach ($schools as $school) {
        app(App\Services\Access\SyncDefaultSchoolRoles::class)->handle($school);
    }
}

/**
 * A user in $school holding the given built-in role(s).
 */
function makeUserWithRole(School $school, UserType $type, string|array $roles, array $attributes = []): User
{
    $user = makeSchoolUser($school, $type, $attributes);

    tenancy()->run($school, fn () => $user->assignRole($roles));

    return $user;
}

/**
 * A student account with its profile, optionally placed in a class.
 */
function makeStudent(School $school, ?App\Models\SchoolClass $class = null, array $attributes = []): App\Models\StudentProfile
{
    $user = makeUserWithRole($school, UserType::Student, 'Student', $attributes);

    return tenancy()->run($school, fn () => App\Models\StudentProfile::query()->create([
        'user_id' => $user->id,
        'admission_number' => 'ADM-'.$user->id,
        'class_id' => $class?->id,
        'status' => App\Enums\StudentStatus::Active,
    ]));
}

/**
 * A parent account linked to the given children.
 */
function makeParentOf(School $school, App\Models\StudentProfile ...$children): App\Models\ParentProfile
{
    $user = makeUserWithRole($school, UserType::Parent, 'Parent');

    return tenancy()->run($school, function () use ($user, $children) {
        $parent = App\Models\ParentProfile::query()->create(['user_id' => $user->id]);
        $parent->children()->attach(collect($children)->pluck('id')->all(), ['relation' => 'guardian']);

        return $parent;
    });
}

/**
 * A staff profile for an existing staff account.
 */
function makeStaffProfile(School $school, User $user): App\Models\StaffProfile
{
    return tenancy()->run($school, fn () => App\Models\StaffProfile::query()->create([
        'user_id' => $user->id,
        'employee_id' => 'EMP-'.$user->id,
    ]));
}
