<?php

use App\Enums\BookIssueStatus;
use App\Enums\FineStatus;
use App\Enums\InvoiceStatus;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\BookIssue;
use App\Models\Campus;
use App\Models\LibraryBook;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Services\Fees\Invoices;
use App\Services\Library\Library;
use App\Services\Schools\SeedSchoolDefaults;
use App\Services\Students\StudentRecords;
use App\Tables\ActionFailed;
use App\Tables\BookIssuesTable;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    app(SeedSchoolDefaults::class)->handle($this->school);
    tenancy()->set($this->school);

    // 7-day loans, one renewal, two books per student, 10 a day late.
    $settings = $this->school->settings ?? [];
    $settings['library'] = ['loan_days' => 7, 'max_renewals' => 1, 'max_books_student' => 2, 'max_books_staff' => 5, 'fine_per_day' => 10];
    $this->school->update(['settings' => $settings]);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    $this->librarian = makeUserWithRole($this->school, UserType::Librarian, 'Librarian', ['name' => 'Laila Librarian']);
    makeStaffProfile($this->school, $this->librarian);
    $this->teacher = makeUserWithRole($this->school, UserType::Teacher, 'Teacher', ['name' => 'Tariq Teacher']);
    makeStaffProfile($this->school, $this->teacher);

    AcademicYear::factory()->current()->create(['start_date' => '2026-08-01', 'end_date' => '2027-06-30']);
    $campus = Campus::query()->create(['name' => 'Main', 'is_main' => true]);
    $class = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 6', 'numeric_level' => 6]);

    $this->student = app(StudentRecords::class)->admit(['name' => 'Asma Khan', 'class_id' => $class->id],
        [['name' => 'Kamran Khan', 'phone' => '0301', 'relation' => 'father']])->student;
    $this->parent = $this->student->parents()->with('user')->first()->user;
    foreach ([$this->student->user, $this->parent, $this->teacher, $this->librarian] as $user) {
        $user->forceFill(['must_change_password' => false])->save();
    }

    $this->library = app(Library::class);
    $this->book = $this->library->saveBook(['title' => 'Matilda', 'author' => 'Roald Dahl', 'quantity' => 2, 'price' => 500]);
    $this->other = $this->library->saveBook(['title' => 'Charlotte\'s Web', 'quantity' => 1]);
    $this->third = $this->library->saveBook(['title' => 'The Hobbit', 'quantity' => 1]);

    $this->today = Carbon::parse('2026-10-20');
    Carbon::setTestNow('2026-10-20 09:00:00');

    $this->actingAs($this->librarian);
});

afterEach(fn () => Carbon::setTestNow());

it('keeps the copies on the shelf in step with loans and the catalogue', function () {
    $issue = $this->library->issue($this->book, $this->student->user, $this->today, $this->today->copy()->addDays(7), null, $this->librarian);

    expect($this->book->fresh()->available_quantity)->toBe(1);

    // The catalogue can't hold fewer copies than are out.
    expect(fn () => $this->library->saveBook(['title' => 'Matilda', 'quantity' => 0], $this->book))->toThrow(ActionFailed::class);
    $this->library->saveBook(['title' => 'Matilda', 'quantity' => 4], $this->book);
    expect($this->book->fresh()->available_quantity)->toBe(3);

    // Can't remove a book with copies out.
    expect(fn () => $this->library->deleteBook($this->book->fresh(), $this->admin))->toThrow(ActionFailed::class);

    $this->library->receive($issue, $this->today, 0, null, $this->librarian);
    expect($this->book->fresh()->available_quantity)->toBe(4)
        ->and($issue->fresh()->status)->toBe(BookIssueStatus::Returned)
        ->and($issue->fresh()->fine_status)->toBe(FineStatus::None);
});

it('enforces the lending rules', function () {
    $due = $this->today->copy()->addDays(7);
    $this->library->issue($this->book, $this->student->user, $this->today, $due, null, $this->librarian);

    // Same title twice, and parents can't borrow.
    expect(fn () => $this->library->issue($this->book, $this->student->user, $this->today, $due, null, $this->librarian))->toThrow(ActionFailed::class, 'already has a copy')
        ->and(fn () => $this->library->issue($this->other, $this->parent, $this->today, $due, null, $this->librarian))->toThrow(ActionFailed::class, 'can\'t borrow');

    // Two books at most for a student.
    $this->library->issue($this->other, $this->student->user, $this->today, $due, null, $this->librarian);
    expect(fn () => $this->library->issue($this->third, $this->student->user, $this->today, $due, null, $this->librarian))->toThrow(ActionFailed::class, 'the most they can borrow');

    // No copies left of Charlotte's Web for the teacher.
    expect(fn () => $this->library->issue($this->other, $this->teacher, $this->today, $due, null, $this->librarian))->toThrow(ActionFailed::class, 'Every copy');

    // An overdue book blocks new loans.
    $late = $this->library->issue($this->third, $this->teacher, $this->today->copy()->subDays(10), $this->today->copy()->subDay(), null, $this->librarian);
    expect($late->isOverdue())->toBeTrue()
        ->and(fn () => $this->library->issue($this->book, $this->teacher, $this->today, $due, null, $this->librarian))->toThrow(ActionFailed::class, 'overdue');
});

it('renews loans within the limit and charges fines for late and lost books', function () {
    $issue = $this->library->issue($this->book, $this->student->user, $this->today->copy()->subDays(5), $this->today->copy()->addDays(2), null, $this->librarian);

    $this->library->renew($issue, $this->librarian);
    expect($issue->fresh()->due_on->toDateString())->toBe('2026-10-27')
        ->and(fn () => $this->library->renew($issue->fresh(), $this->librarian))->toThrow(ActionFailed::class, 'the most allowed');

    // Back three days late: 3 × 10.
    $returnedOn = Carbon::parse('2026-10-30');
    Carbon::setTestNow('2026-10-30 10:00:00');
    expect(Library::fineFor($issue->fresh(), $returnedOn))->toBe(30.0);
    $this->library->receive($issue->fresh(), $returnedOn, 30, null, $this->librarian);

    expect($issue->fresh()->fine_status)->toBe(FineStatus::Unpaid)
        ->and((float) $issue->fresh()->fine_amount)->toBe(30.0)
        ->and($issue->fresh()->daysLate())->toBe(3);

    $this->library->settleFine($issue->fresh(), FineStatus::Paid, $this->librarian);
    expect($issue->fresh()->fine_status)->toBe(FineStatus::Paid)
        ->and(fn () => $this->library->settleFine($issue->fresh(), FineStatus::Waived, $this->librarian))->toThrow(ActionFailed::class);

    // Lost: the copy leaves the catalogue.
    $lost = $this->library->issue($this->other, $this->teacher, Carbon::parse('2026-10-30'), Carbon::parse('2026-11-06'), null, $this->librarian);
    $this->library->markLost($lost, Carbon::parse('2026-10-30'), 450, 'Left on the bus', $this->librarian);

    expect($this->other->fresh()->quantity)->toBe(0)
        ->and($this->other->fresh()->available_quantity)->toBe(0)
        ->and($lost->fresh()->status)->toBe(BookIssueStatus::Lost)
        ->and($lost->fresh()->fine_status)->toBe(FineStatus::Unpaid);
});

it('adds a student\'s fine to their fees, and owes it again if that invoice is cancelled', function () {
    $issue = $this->library->issue($this->book, $this->student->user, $this->today->copy()->subDays(10), $this->today->copy()->subDays(3), null, $this->librarian);
    $this->library->receive($issue, $this->today, 30, null, $this->librarian);

    // A librarian can't raise invoices; the admin can.
    expect(app(BookIssuesTable::class)->withParams(['scope' => 'fines'])->action('bill')->isAllowed($this->librarian, $issue->fresh()))->toBeFalse()
        ->and(app(BookIssuesTable::class)->withParams(['scope' => 'fines'])->action('bill')->isAllowed($this->admin, $issue->fresh()))->toBeTrue();

    $invoice = $this->library->billFine($issue->fresh(), $this->admin);

    expect((float) $invoice->total_amount)->toBe(30.0)
        ->and($invoice->student_profile_id)->toBe($this->student->id)
        ->and($invoice->items()->sole()->billable_type)->toBe('book_issue')
        ->and($issue->fresh()->fine_status)->toBe(FineStatus::Invoiced)
        ->and($issue->fresh()->invoice_id)->toBe($invoice->id);

    app(Invoices::class)->cancel($invoice, 'Raised by mistake', $this->admin);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and($issue->fresh()->fine_status)->toBe(FineStatus::Unpaid)
        ->and($issue->fresh()->invoice_id)->toBeNull();

    // A teacher's fine can't go on fees.
    $staffLoan = $this->library->issue($this->other, $this->teacher, $this->today->copy()->subDays(9), $this->today->copy()->subDays(2), null, $this->librarian);
    $this->library->receive($staffLoan, $this->today, 20, null, $this->librarian);
    expect(fn () => $this->library->billFine($staffLoan->fresh(), $this->admin))->toThrow(ActionFailed::class);
});

it('issues and takes back books from the library desk', function () {
    $this->get(route('admin.library.index'))->assertOk()->assertSee('Matilda');
    $this->get(route('admin.library.index', ['tab' => 'loans']))->assertOk()->assertSee('No books out on loan');

    Volt::test('forms.book-issue', ['book' => $this->book->id])
        ->set('borrowerSearch', 'Asma')
        ->assertSee('Asma Khan')
        ->call('pickBorrower', $this->student->user_id)
        ->assertSet('due_on', '2026-10-27')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('library-updated');

    $issue = BookIssue::query()->sole();
    expect($issue->user_id)->toBe($this->student->user_id);

    // The borrower and a parent hear about it.
    expect($this->student->user->notifications()->sole()->data['title'])->toBe('Book borrowed: Matilda')
        ->and($this->parent->notifications()->sole()->data['url'])->toContain('child='.$this->student->id);

    Carbon::setTestNow('2026-10-29 10:00:00');
    Volt::test('forms.book-return', ['issue' => $issue->id])
        ->assertSet('date', '2026-10-29')
        ->assertSet('fine', '20')
        ->set('fine', '15')
        ->call('save')
        ->assertHasNoErrors();

    expect($issue->fresh()->status)->toBe(BookIssueStatus::Returned)
        ->and((float) $issue->fresh()->fine_amount)->toBe(15.0);

    Volt::test('forms.library-book')
        ->set('title', 'Number Fun')
        ->set('quantity', '3')
        ->call('save')
        ->assertHasNoErrors();

    expect(LibraryBook::query()->where('title', 'Number Fun')->value('available_quantity'))->toBe(3);

    // Teachers don't run the library.
    $this->actingAs($this->teacher);
    $this->get(route('admin.library.index'))->assertForbidden();
    Volt::test('forms.book-issue')->assertForbidden();
});

it('saves the lending rules for library managers only', function () {
    Volt::test('forms.library-settings')
        ->assertSet('loan_days', '7')
        ->set('loan_days', '21')
        ->set('fine_per_day', '5')
        ->call('save')
        ->assertHasNoErrors();

    expect(Library::settings()['loan_days'])->toBe(21)
        ->and(Library::settings()['fine_per_day'])->toBe(5.0);
});

it('reminds borrowers the day before and once overdue', function () {
    $dueTomorrow = $this->library->issue($this->book, $this->student->user, $this->today, $this->today->copy()->addDay(), null, $this->librarian);
    $overdue = $this->library->issue($this->other, $this->teacher, $this->today->copy()->subDays(9), $this->today->copy()->subDays(2), null, $this->librarian);
    $this->student->user->notifications()->delete();
    $this->parent->notifications()->delete();
    $this->teacher->notifications()->delete();

    $this->artisan('library:send-reminders')->assertSuccessful();

    expect($this->student->user->notifications()->sole()->data['title'])->toBe('Library book due tomorrow: Matilda')
        ->and($this->parent->notifications()->count())->toBe(1)
        ->and($this->teacher->notifications()->sole()->data['title'])->toBe('Library book overdue: Charlotte\'s Web')
        ->and($this->teacher->notifications()->sole()->data['url'])->toContain('/my/library');

    // Not again the next run.
    $this->artisan('library:send-reminders')->assertSuccessful();
    expect($this->student->user->notifications()->count())->toBe(1)
        ->and($this->teacher->notifications()->count())->toBe(1);
});

it('shows borrowers their books in their own portal', function () {
    $this->library->issue($this->book, $this->student->user, $this->today->copy()->subDays(10), $this->today->copy()->subDays(3), null, $this->librarian);
    $this->library->issue($this->other, $this->teacher, $this->today, $this->today->copy()->addDays(7), null, $this->librarian);

    $this->actingAs($this->student->user)->get(route('student.library'))
        ->assertOk()->assertSee('Matilda')->assertSee('3 days overdue');
    $this->actingAs($this->student->user)->get(route('student.dashboard'))
        ->assertOk()->assertSee('1 library book out');
    $this->actingAs($this->parent)->get(route('parent.library'))
        ->assertOk()->assertSee('Matilda');

    // Staff see theirs, and the menu lists it once they have borrowed.
    $this->actingAs($this->teacher)->get(route('teacher.my-library'))
        ->assertOk()->assertSee('Charlotte');
    $this->actingAs($this->teacher)->get(route('teacher.dashboard'))
        ->assertSee(route('teacher.my-library'));

    // A plan without the library hides it.
    $this->school->update(['plan_id' => Plan::factory()->create(['features' => ['finance']])->id]);
    $this->actingAs($this->student->user)->get(route('student.library'))->assertForbidden();
    $this->actingAs($this->librarian)->get(route('admin.library.index'))->assertForbidden();
});
