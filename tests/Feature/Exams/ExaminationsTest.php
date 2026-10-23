<?php

use App\Enums\ExamStatus;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\Examination;
use App\Models\ExamRemark;
use App\Models\ExamSchedule;
use App\Models\GradeScale;
use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubjectClass;
use App\Services\Exams\Gradebook;
use App\Services\Exams\ResultPublisher;
use App\Services\Exams\ResultSheet;
use App\Services\Schools\SeedSchoolDefaults;
use App\Services\Students\StudentRecords;
use App\Tables\ActionFailed;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    app(SeedSchoolDefaults::class)->handle($this->school);
    tenancy()->set($this->school);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    $this->teacherUser = makeUserWithRole($this->school, UserType::Teacher, 'Teacher');
    $this->teacher = makeStaffProfile($this->school, $this->teacherUser);

    $this->year = AcademicYear::factory()->current()->create(['start_date' => '2026-08-01', 'end_date' => '2027-06-30']);
    $campus = Campus::query()->create(['name' => 'Main', 'is_main' => true]);
    $this->class = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 6', 'numeric_level' => 6]);
    $this->a = $this->class->sections()->create(['name' => 'A', 'class_teacher_id' => $this->teacher->id]);
    $this->b = $this->class->sections()->create(['name' => 'B']);

    $this->math = Subject::query()->create(['code' => 'MATH', 'name' => 'Mathematics']);
    $this->eng = Subject::query()->create(['code' => 'ENG', 'name' => 'English']);

    // The teacher teaches maths to section A only; English is taught class-wide by nobody.
    SubjectClass::query()->create(['academic_year_id' => $this->year->id, 'class_id' => $this->class->id, 'section_id' => $this->a->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacher->id]);
    SubjectClass::query()->create(['academic_year_id' => $this->year->id, 'class_id' => $this->class->id, 'section_id' => $this->b->id, 'subject_id' => $this->math->id]);
    SubjectClass::query()->create(['academic_year_id' => $this->year->id, 'class_id' => $this->class->id, 'section_id' => null, 'subject_id' => $this->eng->id]);

    $records = app(StudentRecords::class);
    $this->asma = $records->admit(['name' => 'Asma', 'class_id' => $this->class->id, 'section_id' => $this->a->id, 'roll_number' => '1'],
        [['name' => 'Asma Dad', 'phone' => '0301', 'relation' => 'father']])->student;
    $this->bilal = $records->admit(['name' => 'Bilal', 'class_id' => $this->class->id, 'section_id' => $this->a->id, 'roll_number' => '2'])->student;
    $this->chand = $records->admit(['name' => 'Chand', 'class_id' => $this->class->id, 'section_id' => $this->b->id])->student;

    $this->exam = Examination::query()->create([
        'academic_year_id' => $this->year->id, 'name' => 'Mid-term', 'start_date' => '2026-12-01', 'end_date' => '2026-12-10',
        'status' => ExamStatus::Scheduled,
    ]);

    $this->mathPaper = ExamSchedule::query()->create(['examination_id' => $this->exam->id, 'class_id' => $this->class->id, 'subject_id' => $this->math->id, 'max_marks' => 100, 'passing_marks' => 40]);
    $this->engPaper = ExamSchedule::query()->create(['examination_id' => $this->exam->id, 'class_id' => $this->class->id, 'subject_id' => $this->eng->id, 'max_marks' => 50, 'passing_marks' => 20]);

    $this->actingAs($this->admin);
});

function enterMarks(ExamSchedule $paper, ?App\Models\Section $section, array $entries, ?App\Models\User $by = null): int
{
    return app(Gradebook::class)->save(test()->exam, $paper->load('schoolClass', 'subject'), $section, $entries, $by ?? test()->admin);
}

it('gives every school a default grade scale', function () {
    $scale = GradeScale::query()->with('items')->where('is_default', true)->sole();

    expect($scale->items->pluck('grade')->all())->toBe(['A+', 'A', 'B', 'C', 'D', 'E', 'F'])
        ->and($scale->gradeFor(91)->grade)->toBe('A+')
        ->and($scale->gradeFor(89.99)->grade)->toBe('A')
        ->and($scale->gradeFor(0)->grade)->toBe('F');
});

it('builds grade bands without gaps from starting percentages', function () {
    Volt::test('forms.grade-scale')
        ->set('name', 'Simple')
        ->set('items', [
            ['grade' => 'Pass', 'from' => '40', 'point' => '', 'remark' => 'Passed'],
            ['grade' => 'Merit', 'from' => '70', 'point' => '', 'remark' => ''],
            ['grade' => 'Fail', 'from' => '0', 'point' => '', 'remark' => ''],
        ])
        ->set('is_default', true)
        ->call('save')
        ->assertHasNoErrors();

    $scale = GradeScale::query()->with('items')->where('name', 'Simple')->sole();
    expect($scale->is_default)->toBeTrue()
        ->and(GradeScale::query()->where('is_default', true)->count())->toBe(1)
        ->and($scale->items->map(fn ($item) => [$item->grade, (float) $item->min_percentage, (float) $item->max_percentage])->all())
        ->toBe([['Merit', 70.0, 100.0], ['Pass', 40.0, 69.99], ['Fail', 0.0, 39.99]])
        ->and($scale->gradeFor(69.995)->grade)->toBe('Pass');

    Volt::test('forms.grade-scale')
        ->set('name', 'Gappy')
        ->set('items', [['grade' => 'A', 'from' => '50', 'point' => '', 'remark' => ''], ['grade' => 'B', 'from' => '10', 'point' => '', 'remark' => '']])
        ->call('save')
        ->assertHasErrors('items');
});

it('creates an examination within its academic year', function () {
    Volt::test('forms.examination')
        ->assertSet('academic_year_id', (string) $this->year->id)
        ->set('name', 'Finals')
        ->set('start_date', '2027-07-01')
        ->set('end_date', '2027-07-05')
        ->call('save')
        ->assertHasErrors(['start_date']);

    Volt::test('forms.examination')
        ->set('name', 'Finals')
        ->set('start_date', '2027-05-01')
        ->set('end_date', '2027-05-10')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Examination::query()->where('name', 'Finals')->value('status'))->toBe(ExamStatus::Draft);
});

it('sets a class\'s date sheet from its allocated subjects', function () {
    $exam = Examination::query()->create(['academic_year_id' => $this->year->id, 'name' => 'Unit test', 'start_date' => '2026-10-01', 'end_date' => '2026-10-05']);

    Volt::test('exams.date-sheet', ['examination' => $exam->id])
        ->assertSet('class_id', (string) $this->class->id)
        ->assertSee('Mathematics')
        ->assertSee('English')
        ->set("rows.{$this->eng->id}.on", false)
        ->set("rows.{$this->math->id}.exam_date", '2026-10-02')
        ->set("rows.{$this->math->id}.max_marks", '50')
        ->set("rows.{$this->math->id}.passing_marks", '20')
        ->call('save')
        ->assertHasNoErrors();

    $papers = ExamSchedule::query()->where('examination_id', $exam->id)->get();
    expect($papers)->toHaveCount(1)
        ->and($papers->first()->subject_id)->toBe($this->math->id)
        ->and((float) $papers->first()->max_marks)->toBe(50.0)
        ->and($papers->first()->exam_date->toDateString())->toBe('2026-10-02');
});

it('refuses to drop a subject that already has marks', function () {
    enterMarks($this->engPaper, $this->a, [$this->asma->id => ['marks' => '30']]);

    Volt::test('exams.date-sheet', ['examination' => $this->exam->id])
        ->set("rows.{$this->eng->id}.on", false)
        ->call('save')
        ->assertDispatched('notify', type: 'error');

    expect($this->engPaper->fresh()->trashed())->toBeFalse();
});

it('shows teachers only the sheets of subjects they teach', function () {
    $sheets = app(Gradebook::class)->sheetsFor($this->teacherUser, $this->exam);

    expect($sheets->keys()->all())->toBe([Gradebook::key($this->mathPaper->id, $this->a->id)])
        ->and(app(Gradebook::class)->sheetsFor($this->admin, $this->exam))->toHaveCount(4)
        ->and(app(Gradebook::class)->remarkGroupsFor($this->teacherUser, $this->exam)->keys()->all())->toBe(['r-'.$this->class->id.'-'.$this->a->id]);
});

it('enters marks with grades worked out from the scale', function () {
    $this->actingAs($this->teacherUser);
    $key = Gradebook::key($this->mathPaper->id, $this->a->id);

    Volt::test('exams.marks-sheet', ['examination' => $this->exam->id, 'sheet' => $key])
        ->assertSeeInOrder(['Asma', 'Bilal'])
        ->set("entries.{$this->asma->id}.marks", '92')
        ->set("entries.{$this->bilal->id}.absent", true)
        ->call('save')
        ->assertHasNoErrors();

    $asma = Mark::query()->where('student_profile_id', $this->asma->id)->sole();
    expect((float) $asma->marks_obtained)->toBe(92.0)
        ->and($asma->grade)->toBe('A+')
        ->and($asma->entered_by)->toBe($this->teacherUser->id)
        ->and(Mark::query()->where('student_profile_id', $this->bilal->id)->value('is_absent'))->toBeTrue();

    // Over the total is refused; another teacher's sheet is off limits.
    Volt::test('exams.marks-sheet', ['examination' => $this->exam->id, 'sheet' => $key])
        ->set("entries.{$this->asma->id}.marks", '120')
        ->call('save')
        ->assertHasErrors("entries.{$this->asma->id}.marks");

    Volt::test('exams.marks-sheet', ['examination' => $this->exam->id, 'sheet' => Gradebook::key($this->mathPaper->id, $this->b->id)])
        ->assertForbidden();
});

it('clears a mark when its box is emptied', function () {
    enterMarks($this->mathPaper, $this->a, [$this->asma->id => ['marks' => '50']]);
    enterMarks($this->mathPaper, $this->a, [$this->asma->id => ['marks' => '']]);

    expect(Mark::query()->count())->toBe(0);
});

it('locks marks for drafts and published results', function () {
    $this->exam->update(['status' => ExamStatus::Draft]);
    expect(fn () => enterMarks($this->mathPaper, $this->a, [$this->asma->id => ['marks' => '50']]))->toThrow(ActionFailed::class);

    $this->exam->update(['status' => ExamStatus::Published]);
    expect(fn () => enterMarks($this->mathPaper, $this->a, [$this->asma->id => ['marks' => '50']]))->toThrow(ActionFailed::class, 'published');
});

it('works out totals, results and positions', function () {
    enterMarks($this->mathPaper, null, [
        $this->asma->id => ['marks' => '80'],
        $this->bilal->id => ['marks' => '30'],
        $this->chand->id => ['marks' => '80'],
    ]);
    enterMarks($this->engPaper, null, [
        $this->asma->id => ['marks' => '40'],
        $this->bilal->id => ['marks' => '45'],
        $this->chand->id => ['marks' => '40'],
    ]);

    $rows = app(ResultSheet::class)->forClass($this->exam, $this->class)['rows']->keyBy(fn ($row) => $row['student']->id);

    expect($rows[$this->asma->id]['percentage'])->toBe(80.0)
        ->and($rows[$this->asma->id]['grade'])->toBe('A')
        ->and($rows[$this->asma->id]['passed'])->toBeTrue()
        ->and($rows[$this->asma->id]['rank'])->toBe(1)
        ->and($rows[$this->chand->id]['rank'])->toBe(1)
        ->and($rows[$this->bilal->id]['rank'])->toBe(3)
        ->and($rows[$this->bilal->id]['passed'])->toBeFalse()
        ->and($rows[$this->bilal->id]['failed'])->toBe(['Mathematics']);

    // Section A on its own.
    $sectionA = app(ResultSheet::class)->forClass($this->exam, $this->class, $this->a)['rows'];
    expect($sectionA)->toHaveCount(2);
});

it('leaves incomplete results unranked', function () {
    enterMarks($this->mathPaper, null, [$this->asma->id => ['marks' => '80']]);

    $row = app(ResultSheet::class)->forClass($this->exam, $this->class)['rows']->first(fn ($row) => $row['student']->id === $this->asma->id);

    expect($row['complete'])->toBeFalse()->and($row['rank'])->toBeNull()->and($row['passed'])->toBeNull();
});

it('lets the class teacher write report card remarks', function () {
    $this->actingAs($this->teacherUser);

    Volt::test('exams.remarks-sheet', ['examination' => $this->exam->id, 'group' => 'r-'.$this->class->id.'-'.$this->a->id])
        ->set("remarks.{$this->asma->id}", 'A pleasure to teach.')
        ->call('save')
        ->assertHasNoErrors();

    expect(ExamRemark::query()->where('student_profile_id', $this->asma->id)->value('remarks'))->toBe('A pleasure to teach.');

    Volt::test('exams.remarks-sheet', ['examination' => $this->exam->id, 'group' => 'r-'.$this->class->id.'-'.$this->b->id])->assertForbidden();
});

it('publishes results, tells students and parents, and can unpublish', function () {
    expect(fn () => app(ResultPublisher::class)->publish($this->exam, $this->admin))->toThrow(ActionFailed::class);

    enterMarks($this->mathPaper, $this->a, [$this->asma->id => ['marks' => '70']]);
    app(ResultPublisher::class)->publish($this->exam, $this->admin);

    $parent = $this->asma->parents()->with('user')->first()->user;
    expect($this->exam->fresh()->status)->toBe(ExamStatus::Published)
        ->and($this->asma->user->notifications()->count())->toBe(1)
        ->and($parent->notifications()->count())->toBe(1);

    app(ResultPublisher::class)->unpublish($this->exam->fresh(), $this->admin);
    expect($this->exam->fresh()->status)->toBe(ExamStatus::Completed);
});

it('shows the examination page and its tabs', function () {
    $this->get(route('admin.examinations.show', $this->exam->id))->assertOk()->assertSee('Mid-term');

    Volt::test('tenant.admin.examinations.show', ['examination' => $this->exam->id])
        ->assertSee('Grade 6')
        ->set('tab', 'results')
        ->assertSeeLivewire('exams.results');
});

it('prints report cards as a page and as PDFs', function () {
    enterMarks($this->mathPaper, null, [$this->asma->id => ['marks' => '81'], $this->bilal->id => ['marks' => '60']]);
    enterMarks($this->engPaper, null, [$this->asma->id => ['marks' => '35'], $this->bilal->id => ['marks' => '30']]);
    ExamRemark::query()->create(['examination_id' => $this->exam->id, 'student_profile_id' => $this->asma->id, 'remarks' => 'Keep it up']);

    $this->get(route('admin.examinations.report-card', ['examination' => $this->exam->id, 'student' => $this->asma->id]))
        ->assertOk()
        ->assertSee('Asma')
        ->assertSee('Keep it up')
        ->assertSee('Position in section');

    $this->get(route('admin.examinations.report-card.pdf', ['examination' => $this->exam->id, 'student' => $this->asma->id]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->get(route('admin.examinations.report-cards', ['examination' => $this->exam->id, 'class' => $this->class->id, 'section' => $this->a->id]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('keeps exams and marks to people allowed to see them', function () {
    $this->actingAs($this->teacherUser);

    $this->get(route('admin.examinations.index'))->assertForbidden();
    $this->get(route('teacher.gradebook.index'))->assertOk();
});
