<?php

use App\Enums\AcademicPeriodStatus;
use App\Enums\UserType;
use App\Models\AcademicYear;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    $this->other = makeSchool(['slug' => 'bravo']);
    seedSchoolRoles($this->school, $this->other);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    $this->foreignYear = tenancy()->run($this->other, fn () => AcademicYear::factory()->create());

    tenancy()->set($this->school);
    $this->current = AcademicYear::factory()->current()->create(['name' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30']);

    $this->actingAs($this->admin);
});

function fillYear($component, string $name, string $start, string $end, string $status = 'upcoming')
{
    return $component->set('name', $name)->set('start_date', $start)->set('end_date', $end)->set('status', $status);
}

it('suggests the year after the latest one', function () {
    Volt::test('forms.academic-year')
        ->assertSet('name', '2027-2028')
        ->assertSet('start_date', '2027-07-01')
        ->assertSet('end_date', '2028-06-30')
        ->assertSet('status', AcademicPeriodStatus::Upcoming->value);
});

it('creates a year, then closes the modal, refreshes tables and confirms', function () {
    fillYear(Volt::test('forms.academic-year'), '2027-2028', '2027-08-01', '2028-06-30')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal')
        ->assertDispatched('refresh-tables')
        ->assertDispatched('notify', type: 'success');

    expect(AcademicYear::where('name', '2027-2028')->first())
        ->school_id->toBe($this->school->id)
        ->status->toBe(AcademicPeriodStatus::Upcoming);
});

it('archives the old current year when a new one is created as current', function () {
    fillYear(Volt::test('forms.academic-year'), '2027-2028', '2027-08-01', '2028-06-30', 'current')->call('save');

    expect($this->current->fresh()->status)->toBe(AcademicPeriodStatus::Archived)
        ->and(AcademicYear::current()->pluck('name')->all())->toBe(['2027-2028']);
});

it('validates the year', function (string $name, string $start, string $end, string $status, string $field) {
    fillYear(Volt::test('forms.academic-year'), $name, $start, $end, $status)
        ->call('save')
        ->assertHasErrors($field);
})->with([
    'missing name' => ['', '2027-08-01', '2028-06-30', 'upcoming', 'name'],
    'duplicate name' => ['2026-2027', '2027-08-01', '2028-06-30', 'upcoming', 'name'],
    'ends before it starts' => ['2027-2028', '2028-06-30', '2027-08-01', 'upcoming', 'end_date'],
    'overlaps another year' => ['2027-2028', '2027-06-01', '2028-06-30', 'upcoming', 'end_date'],
    'unknown status' => ['2027-2028', '2027-08-01', '2028-06-30', 'someday', 'status'],
]);

it('allows the same name in another school', function () {
    fillYear(Volt::test('forms.academic-year'), $this->foreignYear->name, '2040-08-01', '2041-06-30')
        ->call('save')
        ->assertHasNoErrors();
});

it('edits a year', function () {
    Volt::test('forms.academic-year', ['academicYear' => $this->current->id])
        ->assertSet('name', '2026-2027')
        ->set('name', '2026/27')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->current->fresh()->name)->toBe('2026/27');
});

it('cannot open another school\'s year', function () {
    Volt::test('forms.academic-year', ['academicYear' => $this->foreignYear->id])->assertNotFound();
});

it('requires permission to create or edit', function () {
    $this->actingAs(makeUserWithRole($this->school, UserType::Teacher, 'Teacher'));

    Volt::test('forms.academic-year')->assertForbidden();
    Volt::test('forms.academic-year', ['academicYear' => $this->current->id])->assertForbidden();
});

it('serves the academic years page to admins only', function () {
    $this->get('/alpha/admin/academic-years')
        ->assertOk()
        ->assertSee('New academic year')
        ->assertSeeLivewire('components.data-table');

    $this->actingAs(makeUserWithRole($this->school, UserType::Teacher, 'Teacher'))
        ->get('/alpha/admin/academic-years')
        ->assertForbidden();
});

it('adds the page to the admin sidebar', function () {
    $this->get('/alpha/admin')->assertSee('/alpha/admin/academic-years');
});
