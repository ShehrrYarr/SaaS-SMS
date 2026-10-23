<?php

use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubjectClass;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    $this->other = makeSchool(['slug' => 'bravo']);
    seedSchoolRoles($this->school, $this->other);

    $this->foreignSubject = tenancy()->run($this->other, fn () => Subject::query()->create(['code' => 'X', 'name' => 'Foreign']));

    tenancy()->set($this->school);
    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    $this->teacher = makeStaffProfile($this->school, makeUserWithRole($this->school, UserType::Teacher, 'Teacher', ['name' => 'Tara Teacher']));

    $this->year = AcademicYear::factory()->current()->create();
    $campus = Campus::query()->create(['name' => 'Main', 'is_main' => true]);
    $this->class = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 5']);
    $this->a = $this->class->sections()->create(['name' => 'A']);
    $this->b = $this->class->sections()->create(['name' => 'B']);
    $this->math = Subject::query()->create(['code' => 'MATH', 'name' => 'Mathematics']);
    $this->art = Subject::query()->create(['code' => 'ART', 'name' => 'Art']);

    $this->actingAs($this->admin);
});

it('allocates subjects with a teacher per section', function () {
    Volt::test('tenant.admin.subjects.allocation')
        ->assertSet('class_id', (string) $this->class->id)
        ->assertSee('Section A')
        ->assertSee('Tara Teacher')
        ->set("rows.{$this->math->id}.taught", true)
        ->set("rows.{$this->math->id}.periods", '6')
        ->set("rows.{$this->math->id}.teachers.{$this->a->id}", (string) $this->teacher->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('notify', type: 'success');

    $rows = SubjectClass::query()->where('subject_id', $this->math->id)->orderBy('section_id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('section_id')->all())->toBe([$this->a->id, $this->b->id])
        ->and($rows->pluck('teacher_id')->all())->toBe([$this->teacher->id, null])
        ->and($rows->pluck('periods_per_week')->unique()->all())->toBe([6]);
});

it('removes subjects that are unticked, and brings them back', function () {
    $page = Volt::test('tenant.admin.subjects.allocation')
        ->set("rows.{$this->art->id}.taught", true)
        ->call('save');

    expect(SubjectClass::query()->where('subject_id', $this->art->id)->count())->toBe(2);

    $page->set("rows.{$this->art->id}.taught", false)->call('save');
    expect(SubjectClass::query()->where('subject_id', $this->art->id)->count())->toBe(0);

    $page->set("rows.{$this->art->id}.taught", true)->call('save');
    expect(SubjectClass::query()->where('subject_id', $this->art->id)->count())->toBe(2)
        ->and(SubjectClass::withTrashed()->where('subject_id', $this->art->id)->count())->toBe(2);
});

it('shows what is already allocated', function () {
    SubjectClass::query()->create(['academic_year_id' => $this->year->id, 'class_id' => $this->class->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacher->id]);

    Volt::test('tenant.admin.subjects.allocation')
        ->assertSet("rows.{$this->math->id}.taught", true)
        // A whole-class allocation applies to every section.
        ->assertSet("rows.{$this->math->id}.teachers.{$this->b->id}", (string) $this->teacher->id);
});

it('ignores other schools\' subjects and teachers', function () {
    $foreignTeacher = tenancy()->run($this->other, fn () => makeStaffProfile($this->other, makeSchoolUser($this->other)));

    Volt::test('tenant.admin.subjects.allocation')
        ->set("rows.{$this->foreignSubject->id}", ['taught' => true, 'periods' => '', 'teachers' => []])
        ->call('save')
        ->assertHasNoErrors();

    expect(SubjectClass::query()->count())->toBe(0);

    Volt::test('tenant.admin.subjects.allocation')
        ->set("rows.{$this->math->id}.taught", true)
        ->set("rows.{$this->math->id}.teachers.{$this->a->id}", (string) $foreignTeacher->id)
        ->call('save')
        ->assertHasErrors();
});

it('needs the allocate permission', function () {
    $this->actingAs(makeUserWithRole($this->school, UserType::Teacher, 'Teacher'));

    Volt::test('tenant.admin.subjects.allocation')->assertForbidden();
});
