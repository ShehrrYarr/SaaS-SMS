<?php

use App\Enums\AttendanceStatus;
use App\Enums\ExamStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\Examination;
use App\Models\ExamRemark;
use App\Models\ExamSchedule;
use App\Models\Invoice;
use App\Models\Mark;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\StudentAttendance;
use App\Models\Subject;
use App\Services\Exams\ResultPublisher;
use App\Services\Fees\FeeCalculator;
use App\Services\Fees\FeeCollection;
use App\Services\Fees\Invoices;
use App\Services\Schools\SeedSchoolDefaults;
use App\Services\Students\StudentRecords;
use App\Support\Portal;
use App\Support\SelfService;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    app(SeedSchoolDefaults::class)->handle($this->school);
    tenancy()->set($this->school);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    $this->actingAs($this->admin);

    $this->year = AcademicYear::factory()->current()->create(['start_date' => '2026-08-01', 'end_date' => '2027-06-30']);
    $campus = Campus::query()->create(['name' => 'Main', 'is_main' => true]);
    $this->class = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 6', 'numeric_level' => 6]);
    $this->a = $this->class->sections()->create(['name' => 'A']);

    $records = app(StudentRecords::class);
    // Asma and Bilal share a parent (same guardian phone); Chand is another family's.
    $this->asma = $records->admit(['name' => 'Asma Khan', 'class_id' => $this->class->id, 'section_id' => $this->a->id],
        [['name' => 'Kamran Khan', 'phone' => '0301', 'relation' => 'father']])->student;
    $this->bilal = $records->admit(['name' => 'Bilal Khan', 'class_id' => $this->class->id, 'section_id' => $this->a->id],
        [['name' => 'Kamran Khan', 'phone' => '0301', 'relation' => 'father']])->student;
    $this->chand = $records->admit(['name' => 'Chand Ali', 'class_id' => $this->class->id, 'section_id' => $this->a->id],
        [['name' => 'Rubina Ali', 'phone' => '0302', 'relation' => 'mother']])->student;

    $this->parent = $this->asma->parents()->with('user')->first()->user;
    $this->otherParent = $this->chand->parents()->with('user')->first()->user;

    foreach ([$this->asma->user, $this->bilal->user, $this->chand->user, $this->parent, $this->otherParent] as $user) {
        $user->forceFill(['must_change_password' => false])->save();
    }

    // Results: maths for the class, published.
    $math = Subject::query()->create(['code' => 'MATH', 'name' => 'Mathematics']);
    $this->exam = Examination::query()->create(['academic_year_id' => $this->year->id, 'name' => 'Mid-term', 'start_date' => '2026-10-01', 'end_date' => '2026-10-03', 'status' => ExamStatus::Completed]);
    $paper = ExamSchedule::query()->create(['examination_id' => $this->exam->id, 'class_id' => $this->class->id, 'subject_id' => $math->id, 'max_marks' => 100, 'passing_marks' => 40]);
    foreach ([[$this->asma, 82], [$this->bilal, 64], [$this->chand, 71]] as [$student, $marks]) {
        Mark::query()->create(['exam_schedule_id' => $paper->id, 'student_profile_id' => $student->id, 'marks_obtained' => $marks]);
    }
    ExamRemark::query()->create(['examination_id' => $this->exam->id, 'student_profile_id' => $this->asma->id, 'remarks' => 'Works hard and helps others.']);

    Carbon::setTestNow('2026-10-20 09:00:00');

    // Fees: one invoice each for Asma and Chand; Asma has paid part.
    $invoices = app(Invoices::class);
    $this->asmaInvoice = $invoices->create($this->asma, [FeeCalculator::customLine('Tuition October', 2500)], today(), today()->addDays(10), '2026-10', $this->admin);
    $this->chandInvoice = $invoices->create($this->chand, [FeeCalculator::customLine('Tuition October', 2500)], today(), today()->addDays(10), '2026-10', $this->admin);
    $this->payment = app(FeeCollection::class)->collect($this->asmaInvoice, 1000, PaymentMethod::Cash, today(), $this->admin);

    StudentAttendance::query()->create(['student_profile_id' => $this->asma->id, 'academic_year_id' => $this->year->id, 'class_id' => $this->class->id, 'section_id' => $this->a->id, 'date' => '2026-10-19', 'status' => AttendanceStatus::Present]);
    StudentAttendance::query()->create(['student_profile_id' => $this->asma->id, 'academic_year_id' => $this->year->id, 'class_id' => $this->class->id, 'section_id' => $this->a->id, 'date' => '2026-10-20', 'status' => AttendanceStatus::Absent, 'remarks' => 'Fever']);
});

afterEach(fn () => Carbon::setTestNow());

it('shows students their own school life', function () {
    app(ResultPublisher::class)->publish($this->exam, $this->admin);

    $this->actingAs($this->asma->user);

    $this->get(route('student.dashboard'))->assertOk()
        ->assertSee('Asma')->assertSee('50%')->assertSee('Mid-term')->assertSee('Works hard and helps others.');
    $this->get(route('student.attendance', ['month' => '2026-10']))->assertOk()->assertSee('Fever');
    $this->get(route('student.results'))->assertOk()->assertSee('Mid-term')->assertSee('82')->assertSee('Works hard and helps others.');
    $this->get(route('student.fees'))->assertOk()->assertSee($this->asmaInvoice->invoice_number)->assertSee('$1,500.00')
        ->assertDontSee($this->chandInvoice->invoice_number);
    $this->get(route('student.timetable'))->assertOk();
    $this->get(route('student.homework.index'))->assertOk();
});

it('lets parents switch between their children', function () {
    $this->actingAs($this->parent);

    // Children are listed by name; the first is shown until another is picked.
    $this->get(route('parent.dashboard'))->assertOk()->assertSee('Asma Khan')->assertSee('Bilal Khan')->assertDontSee('Chand Ali')
        ->assertSee('Asma Khan&#039;s progress', false);

    $this->get(route('parent.fees', ['child' => $this->bilal->id]))->assertOk()->assertSee('No invoices yet');
    // The pick sticks for the session.
    $this->get(route('parent.fees'))->assertOk()->assertSee('No invoices yet');

    // Someone else's child can't be picked.
    $this->get(route('parent.fees', ['child' => $this->chand->id]))->assertOk()->assertSee('No invoices yet');

    Portal::setCurrent('parent');
    SelfService::flush();
    Volt::test('layout.child-switcher')
        ->assertSee('Bilal Khan')
        ->call('switch', $this->asma->id)
        ->assertRedirect();

    expect(SelfService::children($this->parent)->pluck('id')->all())->toBe([$this->asma->id, $this->bilal->id]);
    SelfService::flush();
    expect(SelfService::student()->id)->toBe($this->asma->id);

    Volt::test('layout.child-switcher')->call('switch', $this->chand->id)->assertNotFound();

    $this->get(route('parent.fees'))->assertOk()->assertSee($this->asmaInvoice->invoice_number);
});

it('serves invoices, receipts and report cards only to the family', function () {
    app(ResultPublisher::class)->publish($this->exam, $this->admin);

    $invoice = route('student.invoices.document', $this->asmaInvoice->id);
    $card = ['examination' => $this->exam->id, 'student' => $this->asma->id];

    $this->actingAs($this->asma->user);
    $this->get($invoice)->assertOk()->assertSee($this->asmaInvoice->invoice_number);
    $this->get(route('student.invoices.pdf', $this->asmaInvoice->id))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('student.receipts.document', $this->payment->id))->assertOk()->assertSee($this->payment->receipt_number);
    $this->get(route('student.report-card', $card))->assertOk()->assertSee('Asma Khan');
    $this->get(route('student.report-card.pdf', $card))->assertOk()->assertHeader('content-type', 'application/pdf');

    $this->actingAs($this->parent);
    $this->get(route('parent.invoices.document', $this->asmaInvoice->id))->assertOk();
    $this->get(route('parent.receipts.pdf', $this->payment->id))->assertOk();
    $this->get(route('parent.report-card', $card))->assertOk();

    $this->actingAs($this->chand->user);
    $this->get($invoice)->assertNotFound();
    $this->get(route('student.receipts.document', $this->payment->id))->assertNotFound();
    $this->get(route('student.report-card', $card))->assertNotFound();

    $this->actingAs($this->otherParent);
    $this->get(route('parent.invoices.pdf', $this->asmaInvoice->id))->assertNotFound();
    $this->get(route('parent.report-card.pdf', $card))->assertNotFound();

    // Unpublished results have no report card yet.
    app(ResultPublisher::class)->unpublish($this->exam->fresh(), $this->admin);
    $this->actingAs($this->asma->user)->get(route('student.report-card', $card))->assertNotFound();
});

it('links result notices to the results pages, one per child for parents', function () {
    app(ResultPublisher::class)->publish($this->exam, $this->admin);

    $studentNotice = $this->asma->user->notifications()->sole()->data;
    $parentNotices = $this->parent->notifications()->where('data->title', 'like', '%results%')->get();

    expect($studentNotice['url'])->toBe(route('student.results', ['exam' => $this->exam->id]))
        ->and($parentNotices)->toHaveCount(2)
        ->and($parentNotices->pluck('data.url')->sort()->values()->all())->toBe(collect([
            route('parent.results', ['exam' => $this->exam->id, 'child' => $this->asma->id]),
            route('parent.results', ['exam' => $this->exam->id, 'child' => $this->bilal->id]),
        ])->sort()->values()->all());
});

it('tells parents about new invoices and payments, unless the school turns it off', function () {
    $notices = $this->parent->notifications()->get()->pluck('data.title');

    expect($notices)->toContain('New fee invoice for Asma Khan')
        ->toContain('Payment received for Asma Khan')
        ->and($this->otherParent->notifications()->get()->pluck('data.title')->all())->toBe(['New fee invoice for Chand Ali']);

    $school = tenant();
    $school->update(['settings' => array_replace_recursive($school->settings ?? [], ['fees' => ['notify_parents' => false]])]);
    tenancy()->set($school->fresh());

    app(Invoices::class)->create($this->bilal, [FeeCalculator::customLine('Trip', 300)], today(), today(), null, $this->admin);

    expect($this->parent->notifications()->count())->toBe(2);
});

it('hides pages the school\'s plan does not include', function () {
    $plan = Plan::factory()->create(['features' => ['finance']]);
    $this->school->update(['plan_id' => $plan->id]);

    $this->actingAs($this->asma->user);
    $this->get(route('student.homework.index'))->assertForbidden();
    $this->get(route('student.results'))->assertForbidden();
    $this->get(route('student.fees'))->assertOk();
    $this->get(route('student.dashboard'))->assertOk()
        ->assertDontSee(route('student.homework.index'))
        ->assertSee(route('student.fees'));
});

it('shows a friendly page when there is no student to show', function () {
    $lonely = makeUserWithRole($this->school, UserType::Parent, 'Parent');
    App\Models\ParentProfile::query()->create(['user_id' => $lonely->id]);

    $this->actingAs($lonely);
    $this->get(route('parent.dashboard'))->assertOk()->assertSee('No children linked yet');
    $this->get(route('parent.attendance'))->assertOk()->assertSee('No children linked yet');
    $this->get(route('parent.fees'))->assertOk()->assertSee('No children linked yet');
});
