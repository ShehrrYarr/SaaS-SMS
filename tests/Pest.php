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
 */
function makeSchoolUser(School $school, UserType $type = UserType::Staff, array $attributes = []): User
{
    return tenancy()->run($school, fn () => User::factory()->type($type)->create($attributes));
}

function makeSuperAdmin(): User
{
    return tenancy()->runCentral(fn () => User::factory()->superAdmin()->create());
}
