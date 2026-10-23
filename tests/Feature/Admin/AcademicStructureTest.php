<?php

use App\Enums\AcademicPeriodStatus;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\Classroom;
use App\Models\Department;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Term;
use App\Tables\CampusesTable;
use App\Tables\ClassesTable;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    $this->other = makeSchool(['slug' => 'bravo']);
    seedSchoolRoles($this->school, $this->other);

    $this->foreignCampus = tenancy()->run($this->other, fn () => Campus::query()->create(['name' => 'Bravo Main', 'is_main' => true]));

    tenancy()->set($this->school);
    $this->main = Campus::query()->create(['name' => 'Main Campus', 'is_main' => true]);
    $this->actingAs(makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin'));
});

it('serves every setup page to admins and keeps teachers out', function (string $path, string $text) {
    $this->get("/alpha/admin/{$path}")->assertOk()->assertSee($text);

    $this->actingAs(makeUserWithRole($this->school, UserType::Teacher, 'Teacher'))
        ->get("/alpha/admin/{$path}")
        ->assertForbidden();
})->with([
    'terms' => ['terms', 'New term'],
    'campuses' => ['campuses', 'New campus'],
    'departments' => ['departments', 'New department'],
    'classes' => ['classes', 'New class'],
    'classrooms' => ['classrooms', 'New room'],
    'subjects' => ['subjects', 'New subject'],
    'allocation' => ['subjects/allocation', 'Subject allocation'],
]);

it('keeps exactly one main campus', function () {
    Volt::test('forms.campus')
        ->set('name', 'City Campus')
        ->set('is_main', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Campus::query()->where('is_main', true)->pluck('name')->all())->toBe(['City Campus']);

    Volt::test('components.data-table', ['table' => CampusesTable::class])
        ->call('runAction', 'make-main', $this->main->id);

    expect($this->main->fresh()->is_main)->toBeTrue()
        ->and(Campus::query()->where('is_main', true)->count())->toBe(1);
});

it('refuses to delete the main campus or one with classes', function () {
    $branch = Campus::query()->create(['name' => 'Branch']);
    SchoolClass::query()->create(['campus_id' => $branch->id, 'name' => 'Grade 1']);

    $table = Volt::test('components.data-table', ['table' => CampusesTable::class]);

    $table->call('runAction', 'delete', $this->main->id)->assertDispatched('notify', fn ($n, $p) => $p['type'] === 'error');
    $table->call('runAction', 'delete', $branch->id)->assertDispatched('notify', fn ($n, $p) => $p['type'] === 'error');

    expect(Campus::query()->count())->toBe(2);
});

it('scopes names and references to the school', function () {
    // The same name is fine in another school...
    Volt::test('forms.campus')->set('name', 'Bravo Main')->call('save')->assertHasNoErrors();

    // ...but another school's campus can't be referenced.
    Volt::test('forms.department')->set('name', 'Science')->set('campus_id', (string) $this->foreignCampus->id)->call('save')->assertHasErrors('campus_id');
    Volt::test('forms.classroom')->set('room_number', '101')->set('campus_id', (string) $this->foreignCampus->id)->call('save')->assertHasErrors('campus_id');
});

it('creates departments, rooms and subjects', function () {
    Volt::test('forms.department')->set('name', 'Science')->set('code', 'SCI')->call('save')->assertHasNoErrors();
    Volt::test('forms.classroom')->assertSet('campus_id', (string) $this->main->id)->set('room_number', '101')->set('capacity', '40')->call('save')->assertHasNoErrors();
    Volt::test('forms.classroom')->set('room_number', '101')->call('save')->assertHasErrors('room_number');
    Volt::test('forms.subject')->set('code', 'math')->set('name', 'Mathematics')->call('save')->assertHasNoErrors();
    Volt::test('forms.subject')->set('code', 'MATH')->set('name', 'Maths again')->call('save')->assertHasErrors('code');

    expect(Department::query()->value('code'))->toBe('SCI')
        ->and(Classroom::query()->value('capacity'))->toBe(40)
        ->and(Subject::query()->value('code'))->toBe('MATH');
});

it('creates a class with its sections', function () {
    $room = Classroom::query()->create(['campus_id' => $this->main->id, 'room_number' => '12']);

    Volt::test('forms.school-class')
        ->set('name', 'Grade 1')
        ->assertSet('sections.0.name', 'A')
        ->set('quickSections', 'B, C, b')
        ->call('addQuickSections')
        ->assertCount('sections', 3)
        ->set('sections.1.classroom_id', (string) $room->id)
        ->call('save')
        ->assertHasNoErrors();

    $class = SchoolClass::query()->where('name', 'Grade 1')->firstOrFail();

    expect($class->sections()->ordered()->pluck('name')->all())->toBe(['A', 'B', 'C'])
        ->and($class->sections()->where('name', 'B')->value('classroom_id'))->toBe($room->id)
        ->and($class->campus_id)->toBe($this->main->id);
});

it('rejects duplicate section names', function () {
    Volt::test('forms.school-class')
        ->set('name', 'Grade 1')
        ->set('sections', [['id' => null, 'name' => 'A', 'capacity' => '', 'classroom_id' => ''], ['id' => null, 'name' => 'a', 'capacity' => '', 'classroom_id' => '']])
        ->call('save')
        ->assertHasErrors('sections.1.name');
});

it('removes sections, but not ones with students, and restores a removed name', function () {
    $class = SchoolClass::query()->create(['campus_id' => $this->main->id, 'name' => 'Grade 2']);
    $a = $class->sections()->create(['name' => 'A']);
    $b = $class->sections()->create(['name' => 'B']);
    makeStudent($this->school, $class)->update(['section_id' => $a->id]);

    // Dropping A (has a student) is refused.
    Volt::test('forms.school-class', ['schoolClass' => $class->id])
        ->call('removeSection', 0)
        ->call('save')
        ->assertHasErrors('sections');

    // Dropping B works; adding B back restores it.
    Volt::test('forms.school-class', ['schoolClass' => $class->id])->call('removeSection', 1)->call('save')->assertHasNoErrors();
    expect(Section::query()->find($b->id))->toBeNull();

    Volt::test('forms.school-class', ['schoolClass' => $class->id])->call('addSection')->assertSet('sections.1.name', 'B')->call('save')->assertHasNoErrors();
    expect(Section::query()->find($b->id))->not->toBeNull();
});

it('will not delete a class with students, and takes sections with it otherwise', function () {
    $busy = SchoolClass::query()->create(['campus_id' => $this->main->id, 'name' => 'Busy']);
    makeStudent($this->school, $busy);
    $empty = SchoolClass::query()->create(['campus_id' => $this->main->id, 'name' => 'Empty']);
    $section = $empty->sections()->create(['name' => 'A']);

    $table = Volt::test('components.data-table', ['table' => ClassesTable::class]);
    $table->call('runAction', 'delete', $busy->id)->assertDispatched('notify', fn ($n, $p) => $p['type'] === 'error');
    $table->call('runAction', 'delete', $empty->id)->assertDispatched('notify', type: 'success');

    expect(SchoolClass::query()->find($busy->id))->not->toBeNull()
        ->and(Section::query()->find($section->id))->toBeNull();
});

it('keeps terms inside their year, apart, and one of them current', function () {
    $year = AcademicYear::factory()->current()->create(['name' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30']);

    Volt::test('forms.term')
        ->assertSet('academic_year_id', (string) $year->id)
        ->assertSet('name', 'Term 1')
        ->assertSet('start_date', '2026-08-01')
        ->set('end_date', '2026-12-20')
        ->call('save')
        ->assertHasNoErrors();

    Volt::test('forms.term')->assertSet('start_date', '2026-12-21')->set('end_date', '2027-08-01')->call('save')->assertHasErrors('end_date');
    Volt::test('forms.term')->set('start_date', '2026-12-01')->set('end_date', '2027-03-01')->call('save')->assertHasErrors('end_date');
    Volt::test('forms.term')->set('end_date', '2027-06-30')->set('status', 'current')->call('save')->assertHasNoErrors();

    expect(Term::query()->current()->pluck('name')->all())->toBe(['Term 2'])
        ->and(Term::query()->where('name', 'Term 1')->first()->status)->toBe(AcademicPeriodStatus::Archived);
});

it('records setup changes in the activity log', function () {
    Volt::test('forms.subject')->set('code', 'ENG')->set('name', 'English')->call('save');

    $this->get('/alpha/admin/activity-log')->assertOk()->assertSee('Subject: English');
});
