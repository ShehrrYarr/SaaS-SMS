<?php

use App\Enums\UserType;
use App\Models\Role;
use App\Services\Access\SyncDefaultSchoolRoles;
use App\Support\Access\PermissionCatalog;
use Database\Seeders\PermissionCatalogSeeder;

beforeEach(function () {
    $this->seed(PermissionCatalogSeeder::class);

    $this->schoolA = makeSchool(['slug' => 'alpha']);
    $this->schoolB = makeSchool(['slug' => 'bravo']);

    $sync = app(SyncDefaultSchoolRoles::class);
    $sync->handle($this->schoolA);
    $sync->handle($this->schoolB);
});

it('expands role patterns from the catalog', function () {
    expect(PermissionCatalog::expand(['homework.*']))
        ->toBe(['homework.view', 'homework.create', 'homework.edit', 'homework.delete', 'homework.grade', 'homework.manage']);
});

it('grants everything except the self-service portals with "*"', function () {
    $all = PermissionCatalog::expand(['*']);

    expect($all)->toContain('portal.admin', 'portal.teacher', 'fees.collect')
        ->not->toContain('portal.student', 'portal.parent')
        ->and(count($all))->toBe(count(PermissionCatalog::schoolPermissions()) - 2);
});

it('fails loudly on unknown permissions', function () {
    PermissionCatalog::expand(['attendance.teleport']);
})->throws(InvalidArgumentException::class);

it('gives every school its own set of built-in roles', function () {
    $roleNames = array_keys(config('access.default_roles'));

    foreach ([$this->schoolA, $this->schoolB] as $school) {
        tenancy()->run($school, function () use ($roleNames) {
            expect(Role::forCurrentSchool()->orderBy('id')->pluck('name')->all())->toBe($roleNames)
                ->and(Role::forCurrentSchool()->where('is_system', false)->count())->toBe(0);
        });
    }
});

it('is safe to re-run role syncing', function () {
    app(SyncDefaultSchoolRoles::class)->handle($this->schoolA);

    tenancy()->run($this->schoolA, fn () => expect(Role::forCurrentSchool()->count())
        ->toBe(count(config('access.default_roles'))));
});

it('keeps a role granted in one school out of every other school', function () {
    $teacher = makeSchoolUser($this->schoolA, UserType::Teacher);

    tenancy()->run($this->schoolA, fn () => $teacher->assignRole('Teacher'));

    tenancy()->run($this->schoolA, function () use ($teacher) {
        expect($teacher->fresh()->can('attendance.mark'))->toBeTrue()
            ->and($teacher->fresh()->canAccessPortal('teacher'))->toBeTrue();
    });

    // The same account evaluated as if it were inside school B holds nothing.
    tenancy()->run($this->schoolB, function () use ($teacher) {
        expect($teacher->fresh()->can('attendance.mark'))->toBeFalse()
            ->and($teacher->fresh()->canAccessPortal('teacher'))->toBeFalse();
    });
});

it('maps portals to their portal permission', function () {
    $accountant = makeSchoolUser($this->schoolA, UserType::Accountant);

    tenancy()->run($this->schoolA, function () use ($accountant) {
        $accountant->assignRole('Accountant');

        expect($accountant->fresh()->accessiblePortals())->toBe(['accounting'])
            ->and($accountant->fresh()->can('fees.collect'))->toBeTrue()
            ->and($accountant->fresh()->can('marks.edit'))->toBeFalse();
    });
});

it('lets the super admin pass every gate in the central console', function () {
    $superAdmin = makeSuperAdmin();

    tenancy()->runCentral(fn () => expect($superAdmin->can('manage-schools'))->toBeTrue());
});
