<?php

use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\Classroom;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubjectClass;
use App\Models\Timetable;
use App\Services\Schools\SeedSchoolDefaults;
use App\Services\Students\StudentRecords;
use App\Services\Timetable\BellSchedule;
use App\Services\Timetable\Timetables;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    app(SeedSchoolDefaults::class)->handle($this->school);
    tenancy()->set($this->school);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    $this->teacherUser = makeUserWithRole($this->school, UserType::Teacher, 'Teacher', ['name' => 'Tariq Teacher']);
    $this->teacher = makeStaffProfile($this->school, $this->teacherUser);

    $this->year = AcademicYear::factory()->current()->create(['start_date' => '2026-08-01', 'end_date' => '2027-06-30']);
    $campus = Campus::query()->create(['name' => 'Main', 'is_main' => true]);
    $this->class = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 6', 'numeric_level' => 6]);
    $this->a = $this->class->sections()->create(['name' => 'A']);
    $this->b = $this->class->sections()->create(['name' => 'B']);
    $this->other = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 7', 'numeric_level' => 7]);

    $math = Subject::query()->create(['code' => 'MATH', 'name' => 'Mathematics']);
    $eng = Subject::query()->create(['code' => 'ENG', 'name' => 'English']);

    // One teacher takes maths in both sections; English covers every section.
    $this->mathA = SubjectClass::query()->create(['academic_year_id' => $this->year->id, 'class_id' => $this->class->id, 'section_id' => $this->a->id, 'subject_id' => $math->id, 'teacher_id' => $this->teacher->id, 'periods_per_week' => 5]);
    $this->mathB = SubjectClass::query()->create(['academic_year_id' => $this->year->id, 'class_id' => $this->class->id, 'section_id' => $this->b->id, 'subject_id' => $math->id, 'teacher_id' => $this->teacher->id]);
    $this->engAll = SubjectClass::query()->create(['academic_year_id' => $this->year->id, 'class_id' => $this->class->id, 'section_id' => null, 'subject_id' => $eng->id]);
    $this->otherMath = SubjectClass::query()->create(['academic_year_id' => $this->year->id, 'class_id' => $this->other->id, 'subject_id' => $math->id]);

    $this->room = Classroom::query()->create(['room_number' => '101', 'name' => 'Lab']);

    $this->actingAs($this->admin);

    // Six 40-minute periods from 08:00 with a 20-minute break after the third.
    app(BellSchedule::class)->save(BellSchedule::generate('08:00', 40, 6, 3, 20));
});

it('keeps the school day in time order', function () {
    $bells = app(BellSchedule::class);

    expect($bells->periods())->toHaveCount(6)
        ->and($bells->period(4))->toBe(['number' => 4, 'start' => '10:20', 'end' => '11:00'])
        ->and($bells->days())->toBe([1, 2, 3, 4, 5, 6]); // Sunday is the default day off

    $bells->save([['start' => '09:00', 'end' => '09:45'], ['start' => '08:00', 'end' => '08:45']]);

    expect($bells->periods()->all())->toBe([
        ['number' => 1, 'start' => '08:00', 'end' => '08:45'],
        ['number' => 2, 'start' => '09:00', 'end' => '09:45'],
    ]);

    expect(fn () => $bells->save([['start' => '08:00', 'end' => '09:00'], ['start' => '08:30', 'end' => '09:30']]))
        ->toThrow(ValidationException::class);
});

it('builds a section\'s week and refuses teacher and room clashes', function () {
    $timetables = app(Timetables::class);

    $timetables->assign($this->class, $this->a, 1, 1, $this->mathA->id, $this->room->id);

    // The maths teacher is busy with section A then.
    try {
        $timetables->assign($this->class, $this->b, 1, 1, $this->mathB->id, null);
        $this->fail('A teacher clash was allowed.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('allocation')
            ->and($e->errors()['allocation'][0])->toContain('Tariq Teacher');
    }

    // So is the lab.
    expect(fn () => $timetables->assign($this->class, $this->b, 1, 1, $this->engAll->id, $this->room->id))
        ->toThrow(ValidationException::class);

    // English in another room is fine, and the teacher is free in period 2.
    $timetables->assign($this->class, $this->b, 1, 1, $this->engAll->id, null);
    $timetables->assign($this->class, $this->b, 1, 2, $this->mathB->id, $this->room->id);

    // Setting the same cell again replaces it.
    $timetables->assign($this->class, $this->a, 1, 1, $this->engAll->id, null);

    expect(Timetable::query()->forGroup($this->class->id, $this->a->id)->count())->toBe(1)
        ->and(Timetable::query()->forGroup($this->class->id, $this->a->id)->value('subject_class_id'))->toBe($this->engAll->id)
        ->and(Timetable::query()->forGroup($this->class->id, $this->b->id)->count())->toBe(2);

    // Another class's subject, or a day the school is closed, is refused.
    expect(fn () => $timetables->assign($this->class, $this->a, 2, 1, $this->otherMath->id, null))->toThrow(ValidationException::class)
        ->and(fn () => $timetables->assign($this->class, $this->a, 7, 1, $this->mathA->id, null))->toThrow(ValidationException::class);
});

it('moves slots to new period times and clears periods that were removed', function () {
    $timetables = app(Timetables::class);
    $first = $timetables->assign($this->class, $this->a, 1, 1, $this->mathA->id, null);
    $last = $timetables->assign($this->class, $this->a, 1, 6, $this->engAll->id, null);

    // Last year's timetable is history: it keeps its periods and times.
    $old = AcademicYear::factory()->create(['name' => '2025-2026', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'status' => App\Enums\AcademicPeriodStatus::Archived]);
    $history = Timetable::query()->create([
        'academic_year_id' => $old->id, 'subject_class_id' => $this->mathA->id, 'class_id' => $this->class->id, 'section_id' => $this->a->id,
        'day_of_week' => 1, 'period_number' => 6, 'start_time' => '11:40:00', 'end_time' => '12:20:00',
    ]);

    $cleared = app(BellSchedule::class)->save(BellSchedule::generate('08:30', 45, 5));

    expect($cleared)->toBe(1)
        ->and(Timetable::query()->find($last->id))->toBeNull()
        ->and(substr(Timetable::query()->find($first->id)->start_time, 0, 5))->toBe('08:30')
        ->and(Timetable::query()->find($history->id)->start_time)->toBe('11:40:00');
});

it('shows teachers their week and students their class\'s', function () {
    $timetables = app(Timetables::class);
    $timetables->assign($this->class, $this->a, 1, 1, $this->mathA->id, $this->room->id);
    $timetables->assign($this->class, $this->b, 2, 3, $this->mathB->id, null);
    $timetables->assign($this->class, $this->b, 3, 1, $this->engAll->id, null);

    $student = app(StudentRecords::class)->admit(['name' => 'Sana', 'class_id' => $this->class->id, 'section_id' => $this->a->id])->student;
    $student->user->forceFill(['must_change_password' => false])->save();

    expect($timetables->forTeacher($this->teacher)->flatten(1))->toHaveCount(2)
        ->and($timetables->forStudent($student)->keys()->all())->toBe(['1-1']);

    $this->actingAs($this->teacherUser)->get(route('teacher.timetable'))
        ->assertOk()->assertSee('Grade 6 · B')->assertSee('Lab');

    $this->actingAs($student->user)->get(route('student.timetable'))
        ->assertOk()->assertSee('Mathematics')->assertSee('Tariq Teacher')->assertDontSee('English');
});

it('lets admins set periods from the timetable page', function () {
    $this->get(route('admin.timetable.index'))->assertOk()->assertSee('Grade 6 · A')->assertSee('Period 6');
    $this->get(route('admin.timetable.index', ['by' => 'teacher']))->assertOk()->assertSee('Tariq Teacher');

    Volt::test('forms.timetable-slot', ['classId' => $this->class->id, 'sectionId' => $this->a->id, 'day' => 2, 'period' => 1])
        ->set('allocation', (string) $this->mathA->id)
        ->set('classroom', (string) $this->room->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('timetable-updated');

    Volt::test('forms.timetable-slot', ['classId' => $this->class->id, 'sectionId' => $this->b->id, 'day' => 2, 'period' => 1])
        ->set('allocation', (string) $this->mathB->id)
        ->call('save')
        ->assertHasErrors('allocation');

    Volt::test('forms.timetable-slot', ['classId' => $this->class->id, 'sectionId' => $this->a->id, 'day' => 2, 'period' => 1])
        ->assertSet('allocation', (string) $this->mathA->id)
        ->call('clear');

    expect(Timetable::query()->count())->toBe(0);

    Volt::test('forms.bell-schedule')
        ->assertCount('periods', 6)
        ->call('removePeriod', 5)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(BellSchedule::class)->periods())->toHaveCount(5);

    // Teachers can look, but not change it.
    $this->actingAs($this->teacherUser);
    Volt::test('forms.timetable-slot', ['classId' => $this->class->id, 'sectionId' => $this->a->id, 'day' => 2, 'period' => 1])->assertForbidden();
});
