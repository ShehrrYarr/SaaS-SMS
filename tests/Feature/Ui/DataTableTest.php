<?php

use App\Enums\AcademicPeriodStatus;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Role;
use App\Models\User;
use App\Tables\AcademicYearsTable;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    $this->other = makeSchool(['slug' => 'bravo']);
    seedSchoolRoles($this->school, $this->other);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');

    $this->foreignYear = tenancy()->run($this->other, fn () => AcademicYear::factory()->create(['name' => 'Bravo Year']));

    tenancy()->set($this->school);

    $this->past = AcademicYear::factory()->archived()->create(['name' => '2025-2026', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30']);
    $this->current = AcademicYear::factory()->current()->create(['name' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30']);
    $this->next = AcademicYear::factory()->create(['name' => '2027-2028', 'start_date' => '2027-08-01', 'end_date' => '2028-06-30']);

    $this->actingAs($this->admin);
});

function yearsTable()
{
    return Volt::test('components.data-table', ['table' => AcademicYearsTable::class]);
}

it('lists only the school\'s own rows in the default order', function () {
    yearsTable()
        ->assertSeeInOrder(['2027-2028', '2026-2027', '2025-2026'])
        ->assertDontSee('Bravo Year');
});

it('searches', function () {
    yearsTable()
        ->set('search', '2025')
        ->assertSee('2025-2026')
        ->assertDontSee('2027-2028')
        ->set('search', 'nothing like this')
        ->assertSee('No matching results');
});

it('sorts by a column and toggles the direction', function () {
    yearsTable()
        ->call('sort', 'name')
        ->assertSet('sortDirection', 'asc')
        ->assertSeeInOrder(['2025-2026', '2026-2027', '2027-2028'])
        ->call('sort', 'name')
        ->assertSet('sortDirection', 'desc')
        ->assertSeeInOrder(['2027-2028', '2026-2027', '2025-2026']);
});

it('ignores sorting by columns that are not sortable', function () {
    yearsTable()->call('sort', 'password')->assertSet('sortBy', 'start_date');
});

it('filters, and ignores filter values it does not offer', function () {
    yearsTable()
        ->set('filters.status', AcademicPeriodStatus::Archived->value)
        ->assertSee('2025-2026')
        ->assertDontSee('2027-2028')
        ->set('filters.status', 'anything-else')
        ->assertSee('2027-2028');
});

it('paginates', function () {
    foreach (range(2010, 2019) as $year) {
        AcademicYear::factory()->archived()->create(['name' => "Old {$year}", 'start_date' => "{$year}-08-01", 'end_date' => ($year + 1).'-06-30']);
    }

    yearsTable()
        ->assertDontSee('Old 2010')
        ->call('gotoPage', 2)
        ->assertSee('Old 2010')
        ->set('perPage', 25)
        ->assertSee('Old 2010')
        ->assertSee('2027-2028');
});

it('only renders table classes', function () {
    Volt::test('components.data-table', ['table' => User::class])->assertStatus(404);
});

it('requires the table\'s permission', function () {
    $this->actingAs(makeUserWithRole($this->school, UserType::Teacher, 'Teacher'));

    yearsTable()->assertForbidden();
});

it('runs a row action and reports the result', function () {
    yearsTable()
        ->call('runAction', 'delete', $this->next->id)
        ->assertDispatched('notify', type: 'success', message: '2027-2028 deleted.');

    expect(AcademicYear::find($this->next->id))->toBeNull();
});

it('refuses an action the business rules forbid', function () {
    yearsTable()
        ->call('runAction', 'delete', $this->current->id)
        ->assertDispatched('notify', fn (string $name, array $params) => $params['type'] === 'error');

    expect(AcademicYear::find($this->current->id))->not->toBeNull();
});

it('never acts on another school\'s rows', function () {
    yearsTable()->call('runAction', 'delete', $this->foreignYear->id)->assertNotFound();

    expect(tenancy()->runCentral(fn () => AcademicYear::find($this->foreignYear->id)))->not->toBeNull();
});

it('checks the action\'s own permission', function () {
    $viewer = Role::findOrCreate('Viewer', 'web');
    $viewer->givePermissionTo(['portal.admin', 'academic-years.view']);
    $this->actingAs(makeUserWithRole($this->school, UserType::Staff, 'Viewer'));

    yearsTable()->call('runAction', 'delete', $this->next->id)->assertForbidden();
});

it('makes a year current and archives the old one', function () {
    yearsTable()->call('runAction', 'make-current', $this->next->id)->assertDispatched('notify');

    expect($this->next->fresh()->status)->toBe(AcademicPeriodStatus::Current)
        ->and($this->current->fresh()->status)->toBe(AcademicPeriodStatus::Archived);
});

it('bulk-deletes the selected rows it is allowed to', function () {
    yearsTable()
        ->set('selected', [(string) $this->past->id, (string) $this->current->id, (string) $this->next->id])
        ->call('runBulkAction', 'delete')
        ->assertSet('selected', [])
        ->assertDispatched('notify', message: '2 records deleted.');

    // The current year is protected and survives.
    expect(AcademicYear::pluck('name')->all())->toBe(['2026-2027']);
});

it('reloads when asked to', function () {
    $table = yearsTable();

    AcademicYear::factory()->create(['name' => '2030-2031', 'start_date' => '2030-08-01', 'end_date' => '2031-06-30']);

    $table->dispatch('refresh-tables')->assertSee('2030-2031');
});
