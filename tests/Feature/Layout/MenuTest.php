<?php

use App\Enums\UserType;
use App\Support\Navigation\Menu;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    tenancy()->set($this->school);

    config(['navigation.test' => [
        ['heading' => 'Main'],
        ['label' => 'Dashboard', 'icon' => 'ri-dashboard-3-line', 'route' => 'admin.dashboard'],
        ['label' => 'Roles', 'route' => 'admin.dashboard', 'can' => 'roles.view'],
        ['heading' => 'Later'],
        ['label' => 'Not built yet', 'route' => 'admin.not-built.index'],
        ['label' => 'Group', 'icon' => 'ri-folder-line', 'children' => [
            ['label' => 'Also not built', 'route' => 'admin.also-not-built.index'],
        ]],
    ]]);
});

it('shows only items the user may see whose screens exist', function () {
    $admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');

    $labels = collect(Menu::build('test', $admin))->map(fn ($item) => $item['heading'] ?? $item['label'])->all();

    // "Later" is dropped because nothing under it is available yet.
    expect($labels)->toBe(['Main', 'Dashboard', 'Roles']);
});

it('hides items the user lacks permission for', function () {
    $teacher = makeUserWithRole($this->school, UserType::Teacher, 'Teacher');

    $labels = collect(Menu::build('test', $teacher))->map(fn ($item) => $item['heading'] ?? $item['label'])->all();

    expect($labels)->toBe(['Main', 'Dashboard']);
});

it('builds every configured portal menu without errors', function (string $menu) {
    $admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');

    expect(Menu::build($menu, $admin))->toBeArray();
})->with(['admin', 'teacher', 'hr', 'accounting', 'student', 'parent']);
