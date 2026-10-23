<?php

use App\Enums\HomeworkStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubjectClass;
use App\Services\Homework\Homeworks;
use App\Services\Schools\SeedSchoolDefaults;
use App\Services\Students\StudentRecords;
use App\Support\Portal;
use App\Tables\ActionFailed;
use App\Tables\HomeworkTable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    app(SeedSchoolDefaults::class)->handle($this->school);
    tenancy()->set($this->school);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    $this->teacherUser = makeUserWithRole($this->school, UserType::Teacher, 'Teacher');
    $this->teacher = makeStaffProfile($this->school, $this->teacherUser);
    $this->otherUser = makeUserWithRole($this->school, UserType::Teacher, 'Teacher');
    $this->otherTeacher = makeStaffProfile($this->school, $this->otherUser);

    $this->year = AcademicYear::factory()->current()->create(['start_date' => '2026-08-01', 'end_date' => '2027-06-30']);
    $campus = Campus::query()->create(['name' => 'Main', 'is_main' => true]);
    $this->class = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 6', 'numeric_level' => 6]);
    $this->a = $this->class->sections()->create(['name' => 'A']);
    $this->b = $this->class->sections()->create(['name' => 'B']);

    $math = Subject::query()->create(['code' => 'MATH', 'name' => 'Mathematics']);
    $this->mathA = SubjectClass::query()->create(['academic_year_id' => $this->year->id, 'class_id' => $this->class->id, 'section_id' => $this->a->id, 'subject_id' => $math->id, 'teacher_id' => $this->teacher->id]);
    $this->mathB = SubjectClass::query()->create(['academic_year_id' => $this->year->id, 'class_id' => $this->class->id, 'section_id' => $this->b->id, 'subject_id' => $math->id, 'teacher_id' => $this->otherTeacher->id]);

    $records = app(StudentRecords::class);
    $this->asma = $records->admit(['name' => 'Asma', 'class_id' => $this->class->id, 'section_id' => $this->a->id, 'roll_number' => '1'],
        [['name' => 'Asma Dad', 'phone' => '0301', 'relation' => 'father']])->student;
    $this->bilal = $records->admit(['name' => 'Bilal', 'class_id' => $this->class->id, 'section_id' => $this->a->id, 'roll_number' => '2'])->student;
    $this->chand = $records->admit(['name' => 'Chand', 'class_id' => $this->class->id, 'section_id' => $this->b->id],
        [['name' => 'Chand Mum', 'phone' => '0302', 'relation' => 'mother']])->student;

    $this->asmaParent = $this->asma->parents()->with('user')->first()->user;
    $this->chandParent = $this->chand->parents()->with('user')->first()->user;

    foreach ([$this->asma->user, $this->bilal->user, $this->chand->user, $this->asmaParent, $this->chandParent] as $user) {
        $user->forceFill(['must_change_password' => false])->save();
    }

    Carbon::setTestNow('2026-10-05 09:00:00');
    $this->actingAs($this->teacherUser);
});

afterEach(fn () => Carbon::setTestNow());

function setHomework(array $attributes = []): Homework
{
    $homework = Homework::query()->create([
        'subject_class_id' => test()->mathA->id,
        'title' => 'Fractions worksheet',
        'assigned_on' => '2026-10-05',
        'due_at' => Carbon::parse('2026-10-07 16:00'),
        'max_marks' => 10,
        'status' => HomeworkStatus::Draft,
        'created_by' => test()->teacherUser->id,
        ...$attributes,
    ]);

    app(Homeworks::class)->publish($homework);

    return $homework->fresh();
}

it('sets homework on the teacher\'s own classes and tells the class and parents', function () {
    Volt::test('forms.homework')
        ->assertSet('subject_class_id', (string) $this->mathA->id)
        ->set('subject_class_id', (string) $this->mathB->id)
        ->set('title', 'Long division')
        ->call('save')
        ->assertHasErrors('subject_class_id');

    Volt::test('forms.homework')
        ->set('title', 'Long division')
        ->set('description', 'Exercise 4.2, questions 1-10.')
        ->set('due_date', '2026-10-08')
        ->set('due_time', '15:30')
        ->set('max_marks', '20')
        ->set('publish', true)
        ->call('save')
        ->assertHasNoErrors();

    $homework = Homework::query()->sole();

    // 15:30 school time (UTC by default) is stored in UTC.
    expect($homework->status)->toBe(HomeworkStatus::Published)
        ->and($homework->published_at)->not->toBeNull()
        ->and($homework->due_at->format('Y-m-d H:i'))->toBe('2026-10-08 15:30')
        ->and($this->asma->user->notifications()->count())->toBe(1)
        ->and($this->asmaParent->notifications()->count())->toBe(1)
        ->and($this->asmaParent->notifications()->first()->data['url'])->toContain('child='.$this->asma->id)
        ->and($this->chand->user->notifications()->count())->toBe(0);

    // A draft tells nobody.
    Volt::test('forms.homework')->set('title', 'Draft only')->set('publish', false)->call('save')->assertHasNoErrors();
    expect(Homework::query()->where('title', 'Draft only')->value('status'))->toBe(HomeworkStatus::Draft)
        ->and($this->asma->user->notifications()->count())->toBe(1);
});

it('keeps homework to the teachers who set it', function () {
    $homework = setHomework();

    expect(app(Homeworks::class)->visibleTo($this->otherUser)->count())->toBe(0)
        ->and(app(Homeworks::class)->visibleTo($this->admin)->count())->toBe(1);

    $this->get(route('teacher.homework.index'))->assertOk();
    Livewire::test('components.data-table', ['table' => HomeworkTable::class])->assertSee('Fractions worksheet');

    $this->get(route('teacher.homework.show', $homework->id))->assertOk()->assertSee('Asma')->assertSee('Bilal')->assertDontSee('Chand');

    $this->actingAs($this->otherUser);
    $this->get(route('teacher.homework.show', $homework->id))->assertNotFound();
    Livewire::test('components.data-table', ['table' => HomeworkTable::class])->assertDontSee('Fractions worksheet');
    Volt::test('forms.homework', ['homework' => $homework->id])->assertForbidden();
});

it('takes work until it is graded, marking it late after the due time', function () {
    $homework = setHomework();
    $homeworks = app(Homeworks::class);

    $first = $homeworks->submit($homework, $this->asma, 'Half done', null);
    expect($first->status)->toBe(SubmissionStatus::Submitted);

    expect(fn () => $homeworks->submit($homework, $this->chand, 'Not my class', null))->toThrow(ActionFailed::class)
        ->and(fn () => $homeworks->submit($homework, $this->bilal, '  ', null))->toThrow(ActionFailed::class);

    Carbon::setTestNow('2026-10-08 10:00:00');
    expect($homeworks->submit($homework, $this->asma, 'All done', null)->status)->toBe(SubmissionStatus::Late)
        ->and(HomeworkSubmission::query()->count())->toBe(1);

    expect(fn () => $homeworks->grade($homework, $this->asma, 11, null, $this->teacherUser))->toThrow(ActionFailed::class);
    $homeworks->grade($homework, $this->asma, 8.5, 'Good effort', $this->teacherUser);

    expect(HomeworkSubmission::query()->sole()->status)->toBe(SubmissionStatus::Graded)
        ->and($this->asma->user->notifications()->latest()->first()->data['title'])->toContain('graded')
        ->and(fn () => $homeworks->submit($homework, $this->asma, 'Changed my mind', null))->toThrow(ActionFailed::class);

    // Sent back, it can be handed in again.
    $homeworks->sendBack(HomeworkSubmission::query()->sole(), 'Show your working', $this->teacherUser);
    expect(HomeworkSubmission::query()->sole()->status)->toBe(SubmissionStatus::Returned)
        ->and($homeworks->submit($homework->fresh(), $this->asma, 'With working', null)->status)->toBe(SubmissionStatus::Late);

    // Paper work can be graded without a submission; closing stops new work.
    $homeworks->grade($homework, $this->bilal, 6, null, $this->teacherUser);
    $homeworks->close($homework);
    expect(fn () => $homeworks->submit($homework->fresh(), $this->asma, 'Again', null))->toThrow(ActionFailed::class)
        ->and($homeworks->summary($homework))->toBe(['expected' => 2, 'handed_in' => 1, 'graded' => 1, 'late' => 1]);
});

it('lets students hand work in from their portal and parents follow it', function () {
    $homework = setHomework();

    $this->actingAs($this->asma->user);
    $this->get(route('student.homework.index'))->assertOk()->assertSee('Fractions worksheet');
    $this->get(route('student.dashboard'))->assertOk()->assertSee('Fractions worksheet');
    $this->get(route('student.homework.show', $homework->id))->assertOk()->assertSee('Hand in');

    Portal::setCurrent('student');
    Volt::test('tenant.family.homework.show', ['homework' => $homework->id])
        ->set('content', 'My answers: 1/2, 3/4')
        ->call('submit')
        ->assertHasNoErrors();

    expect(HomeworkSubmission::query()->sole()->content)->toBe('My answers: 1/2, 3/4');

    // Chand is in another section: the homework isn't theirs.
    $this->actingAs($this->chand->user);
    $this->get(route('student.homework.index'))->assertOk()->assertDontSee('Fractions worksheet');
    $this->get(route('student.homework.show', $homework->id))->assertOk()->assertSee('Homework not found');

    $this->actingAs($this->asmaParent);
    $this->get(route('parent.homework.show', $homework->id))->assertOk()->assertSee('My answers: 1/2, 3/4')->assertDontSee('Hand in again');

    Portal::setCurrent('parent');
    Volt::test('tenant.family.homework.show', ['homework' => $homework->id])
        ->set('content', 'Parent typing')
        ->call('submit')
        ->assertForbidden();
});

it('grades from the review form', function () {
    $homework = setHomework();
    app(Homeworks::class)->submit($homework, $this->asma, 'Answers', null);

    Volt::test('forms.homework-review', ['homework' => $homework->id, 'student' => $this->asma->id])
        ->assertSee('Answers')
        ->set('marks', '12')
        ->call('grade')
        ->assertHasErrors('marks')
        ->set('marks', '9')
        ->set('feedback', 'Neat work')
        ->call('grade')
        ->assertHasNoErrors()
        ->assertDispatched('homework-updated');

    expect(HomeworkSubmission::query()->sole())->status->toBe(SubmissionStatus::Graded)->feedback->toBe('Neat work');

    $this->actingAs($this->otherUser);
    Volt::test('forms.homework-review', ['homework' => $homework->id, 'student' => $this->asma->id])->assertForbidden();
});

it('keeps homework files to the class, the family and the teacher', function () {
    Storage::fake('local');

    $homework = setHomework();
    app(Homeworks::class)->attach($homework, UploadedFile::fake()->create('worksheet.pdf', 20, 'application/pdf'));
    $submission = app(Homeworks::class)->submit($homework->fresh(), $this->asma, null, UploadedFile::fake()->image('page.jpg'));

    $sheet = route('tenant.homework.attachment', $homework->id);
    $work = route('tenant.homework.submission', $submission->id);

    $this->get($sheet)->assertOk();
    $this->get($work)->assertOk();

    $this->actingAs($this->asma->user)->get($sheet)->assertOk();
    $this->actingAs($this->asma->user)->get($work)->assertOk();
    $this->actingAs($this->asmaParent)->get($work)->assertOk();

    $this->actingAs($this->chand->user)->get($sheet)->assertNotFound();
    $this->actingAs($this->bilal->user)->get($work)->assertNotFound();
    $this->actingAs($this->chandParent)->get($work)->assertNotFound();
    $this->actingAs($this->otherUser)->get($work)->assertNotFound();

    // A draft's worksheet stays with the teacher.
    $draft = Homework::query()->create(['subject_class_id' => $this->mathA->id, 'title' => 'Next week', 'assigned_on' => '2026-10-05', 'due_at' => now()->addWeek(), 'status' => HomeworkStatus::Draft]);
    app(Homeworks::class)->attach($draft, UploadedFile::fake()->create('later.pdf', 10, 'application/pdf'));
    $this->actingAs($this->asma->user)->get(route('tenant.homework.attachment', $draft->id))->assertNotFound();
});
