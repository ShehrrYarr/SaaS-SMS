<?php

namespace App\Services\Library;

use App\Enums\BookIssueStatus;
use App\Enums\FineStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\BookIssue;
use App\Models\Invoice;
use App\Models\LibraryBook;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Fees\FeeCalculator;
use App\Services\Fees\FeeSchedule;
use App\Services\Fees\Invoices;
use App\Services\Notifications\FamilyNotifier;
use App\Support\Billing\Money;
use App\Support\Portal;
use App\Support\SelfService;
use App\Tables\ActionFailed;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The library desk: the catalogue's copy counts, lending books to students
 * and staff, renewals, returns, lost books and the fines they bring.
 *
 * Rules come from the school's library settings (loan period, renewals,
 * books per borrower, fine per day late). A borrower with an overdue book
 * can't take another until it is back.
 *
 * Fines are settled at the desk (paid or waived) or, for students, added to
 * a fee invoice; cancelling that invoice puts the fine back as unpaid.
 */
class Library
{
    public function __construct(private readonly Invoices $invoices, private readonly FamilyNotifier $family) {}

    /**
     * @return array{loan_days: int, max_renewals: int, max_books_student: int, max_books_staff: int, fine_per_day: float}
     */
    public static function settings(): array
    {
        $school = tenant();

        return [
            'loan_days' => max(1, (int) $school->setting('library.loan_days', 14)),
            'max_renewals' => max(0, (int) $school->setting('library.max_renewals', 2)),
            'max_books_student' => max(1, (int) $school->setting('library.max_books_student', 3)),
            'max_books_staff' => max(1, (int) $school->setting('library.max_books_staff', 5)),
            'fine_per_day' => max(0, (float) $school->setting('library.fine_per_day', 0)),
        ];
    }

    /**
     * Add a title, or change one. Changing the number of copies moves the
     * shelf count by the same amount; it can't drop below the copies out.
     *
     * @param  array{title: string, author?: string|null, isbn?: string|null, publisher?: string|null, category?: string|null, edition?: string|null, rack_number?: string|null, quantity: int, price?: float|string|null}  $data
     *
     * @throws ActionFailed
     */
    public function saveBook(array $data, ?LibraryBook $book = null): LibraryBook
    {
        $values = [
            'title' => trim($data['title']),
            'author' => self::clean($data['author'] ?? null),
            'isbn' => self::clean($data['isbn'] ?? null),
            'publisher' => self::clean($data['publisher'] ?? null),
            'category' => self::clean($data['category'] ?? null),
            'edition' => self::clean($data['edition'] ?? null),
            'rack_number' => self::clean($data['rack_number'] ?? null),
            'price' => filled($data['price'] ?? null) ? round((float) $data['price'], 2) : null,
        ];
        $quantity = max(0, (int) $data['quantity']);

        if (! $book) {
            return LibraryBook::query()->create([...$values, 'quantity' => $quantity, 'available_quantity' => $quantity]);
        }

        return DB::transaction(function () use ($book, $values, $quantity) {
            $book = LibraryBook::query()->lockForUpdate()->findOrFail($book->id);
            $out = $book->copiesOut();

            if ($quantity < $out) {
                throw new ActionFailed(trans_choice(':count copy is out on loan, so the library can\'t hold fewer than :count.|:count copies are out on loan, so the library can\'t hold fewer than :count.', $out, ['count' => $out]));
            }

            $book->update([...$values, 'quantity' => $quantity, 'available_quantity' => $quantity - $out]);

            return $book;
        });
    }

    /**
     * @throws ActionFailed
     */
    public function deleteBook(LibraryBook $book, User $by): void
    {
        if ($book->issues()->out()->exists()) {
            throw new ActionFailed(__('Copies of :title are out on loan. Take them back before removing the book.', ['title' => $book->title]));
        }

        $book->delete();
    }

    /**
     * Students and staff with an active account can borrow; parents can't.
     */
    public static function canBorrow(User $user): bool
    {
        return $user->status === UserStatus::Active
            && ($user->user_type === UserType::Student || $user->user_type->isStaff());
    }

    public static function limitFor(User $user): int
    {
        $settings = static::settings();

        return $user->user_type === UserType::Student ? $settings['max_books_student'] : $settings['max_books_staff'];
    }

    /**
     * @throws ActionFailed
     */
    public function issue(LibraryBook $book, User $borrower, CarbonInterface $issuedOn, CarbonInterface $dueOn, ?string $remarks, User $by): BookIssue
    {
        if (! static::canBorrow($borrower)) {
            throw new ActionFailed(__(':name can\'t borrow books: only students and staff with an active account can.', ['name' => $borrower->name]));
        }

        if ($dueOn->toDateString() < $issuedOn->toDateString()) {
            throw new ActionFailed(__('The due date can\'t be before the issue date.'));
        }

        return DB::transaction(function () use ($book, $borrower, $issuedOn, $dueOn, $remarks, $by) {
            $book = LibraryBook::query()->lockForUpdate()->findOrFail($book->id);

            if ($book->available_quantity < 1) {
                throw new ActionFailed(__('Every copy of :title is out on loan.', ['title' => $book->title]));
            }

            $loans = BookIssue::query()->out()->where('user_id', $borrower->id)->get(['id', 'library_book_id', 'due_on', 'status']);

            if ($loans->contains('library_book_id', $book->id)) {
                throw new ActionFailed(__(':name already has a copy of :title.', ['name' => $borrower->name, 'title' => $book->title]));
            }

            if ($loans->contains(fn (BookIssue $loan) => $loan->isOverdue())) {
                throw new ActionFailed(__(':name has an overdue book. It needs to come back before they borrow another.', ['name' => $borrower->name]));
            }

            $limit = static::limitFor($borrower);

            if ($loans->count() >= $limit) {
                throw new ActionFailed(trans_choice(':name already has :count book, the most they can borrow at once.|:name already has :count books, the most they can borrow at once.', $limit, ['name' => $borrower->name, 'count' => $limit]));
            }

            $issue = BookIssue::query()->create([
                'library_book_id' => $book->id,
                'user_id' => $borrower->id,
                'issued_on' => $issuedOn->toDateString(),
                'due_on' => $dueOn->toDateString(),
                'status' => BookIssueStatus::Issued,
                'fine_status' => FineStatus::None,
                'remarks' => self::clean($remarks),
                'issued_by' => $by->id,
            ]);

            $book->decrement('available_quantity');

            activity('library')->performedOn($issue)->causedBy($by)
                ->withProperties(['book' => $book->title, 'borrower' => $borrower->name, 'due' => $issue->due_on->toDateString()])
                ->log('Book issued');

            $this->tell($issue->setRelation('book', $book)->setRelation('borrower', $borrower),
                __('Book borrowed: :title', ['title' => $book->title]),
                __('Due back on :date.', ['date' => $issue->due_on->format(tenant()->setting('date_format'))]),
                'info');

            return $issue;
        });
    }

    /**
     * Lend the book for another loan period from today.
     *
     * @throws ActionFailed
     */
    public function renew(BookIssue $issue, User $by, ?CarbonInterface $today = null): BookIssue
    {
        $settings = static::settings();
        $today ??= tenant()->today();

        if (! $issue->isOut()) {
            throw new ActionFailed(__('Only a book that is out on loan can be renewed.'));
        }

        if ($issue->isOverdue($today)) {
            throw new ActionFailed(__('This book is overdue. Take it back (and settle any fine) before lending it again.'));
        }

        if ($issue->renewals >= $settings['max_renewals']) {
            throw new ActionFailed(trans_choice('This loan has been renewed :count time already, the most allowed.|This loan has been renewed :count times already, the most allowed.', $settings['max_renewals'], ['count' => $settings['max_renewals']]));
        }

        $due = $today->copy()->addDays($settings['loan_days']);

        if ($due->toDateString() <= $issue->due_on->toDateString()) {
            throw new ActionFailed(__('It is already due back on :date or later.', ['date' => $issue->due_on->format(tenant()->setting('date_format'))]));
        }

        $issue->update([
            'due_on' => $due->toDateString(),
            'renewals' => $issue->renewals + 1,
            'reminded_at' => null,
            'overdue_notified_at' => null,
        ]);

        activity('library')->performedOn($issue)->causedBy($by)
            ->withProperties(['book' => $issue->book?->title, 'borrower' => $issue->borrower?->name, 'due' => $due->toDateString()])
            ->log('Loan renewed');

        return $issue;
    }

    /**
     * The overdue fine for a loan returned on $on.
     */
    public static function fineFor(BookIssue $issue, CarbonInterface $on): float
    {
        $days = max(0, BookIssue::daysBetween($issue->due_on, $on));

        return round($days * static::settings()['fine_per_day'], 2);
    }

    /**
     * The book came back. $fine is what the borrower owes for it (the
     * suggested overdue fine, or less).
     *
     * @throws ActionFailed
     */
    public function receive(BookIssue $issue, CarbonInterface $on, float $fine, ?string $remarks, User $by): void
    {
        $this->close($issue, BookIssueStatus::Returned, $on, $fine, $remarks, $by);
    }

    /**
     * The borrower lost the book: the copy leaves the catalogue and they owe
     * $fine (usually its price).
     *
     * @throws ActionFailed
     */
    public function markLost(BookIssue $issue, CarbonInterface $on, float $fine, ?string $remarks, User $by): void
    {
        $this->close($issue, BookIssueStatus::Lost, $on, $fine, $remarks, $by);
    }

    /**
     * @param  FineStatus::Paid|FineStatus::Waived  $to
     *
     * @throws ActionFailed
     */
    public function settleFine(BookIssue $issue, FineStatus $to, User $by): void
    {
        if (! in_array($to, [FineStatus::Paid, FineStatus::Waived], true)) {
            throw new ActionFailed(__('A fine can only be marked paid or waived.'));
        }

        if (! $issue->hasFineOwed()) {
            throw new ActionFailed(__('There is no fine owed on this loan.'));
        }

        $issue->update(['fine_status' => $to, 'fine_settled_at' => now(), 'fine_settled_by' => $by->id]);

        activity('library')->performedOn($issue)->causedBy($by)
            ->withProperties(['borrower' => $issue->borrower?->name, 'amount' => (float) $issue->fine_amount])
            ->log($to === FineStatus::Paid ? 'Library fine paid' : 'Library fine waived');
    }

    /**
     * Add a student's fine to a one-off fee invoice, collected with their fees.
     *
     * @throws ActionFailed
     */
    public function billFine(BookIssue $issue, User $by): Invoice
    {
        if (! $issue->hasFineOwed()) {
            throw new ActionFailed(__('There is no fine owed on this loan.'));
        }

        $student = StudentProfile::query()->with('user')->where('user_id', $issue->user_id)->first();

        if (! $student) {
            throw new ActionFailed(__('Only a student\'s fine can be added to fees.'));
        }

        return DB::transaction(function () use ($issue, $student, $by) {
            $issue->loadMissing('book');
            $today = tenant()->today();
            $reason = $issue->status === BookIssueStatus::Lost ? __('lost') : __('returned late');

            $line = [
                ...FeeCalculator::customLine(__('Library fine: :title (:reason)', ['title' => $issue->book?->title, 'reason' => $reason]), $issue->fine_amount),
                'billable_type' => $issue->getMorphClass(),
                'billable_id' => $issue->id,
            ];

            $invoice = $this->invoices->create($student, [$line], $today, $today->copy()->addDays(FeeSchedule::DEFAULT_DUE_DAY), null, $by);

            $issue->update(['fine_status' => FineStatus::Invoiced, 'invoice_id' => $invoice->id, 'fine_settled_at' => now(), 'fine_settled_by' => $by->id]);

            activity('library')->performedOn($issue)->causedBy($by)
                ->withProperties(['borrower' => $student->user?->name, 'amount' => (float) $issue->fine_amount, 'invoice' => $invoice->invoice_number])
                ->log('Library fine added to fees');

            return $invoice;
        });
    }

    /**
     * A fee invoice was cancelled: fines it carried are owed again. Static,
     * so Invoices can call it without depending on this class.
     */
    public static function invoiceCancelled(Invoice $invoice): void
    {
        BookIssue::query()
            ->where('invoice_id', $invoice->id)
            ->where('fine_status', FineStatus::Invoiced)
            ->update(['fine_status' => FineStatus::Unpaid, 'invoice_id' => null, 'fine_settled_at' => null, 'fine_settled_by' => null]);
    }

    /**
     * Notify the borrower (and a student's parents) about a loan.
     */
    public function tell(BookIssue $issue, string $title, string $message, string $color = 'info'): void
    {
        $borrower = $issue->borrower;

        if (! $borrower) {
            return;
        }

        if ($borrower->user_type === UserType::Student) {
            $studentId = StudentProfile::query()->where('user_id', $borrower->id)->value('id');

            if ($studentId) {
                $this->family->notify($studentId, fn (StudentProfile $student, string $portal) => new SystemNotification(
                    $portal === 'parent' ? __(':name · :title', ['name' => $student->user?->name, 'title' => $title]) : $title,
                    $message,
                    SelfService::url($portal, 'library', $student),
                    'ri-book-2-line',
                    $color,
                ));
            }

            return;
        }

        if ($borrower->status === UserStatus::Active) {
            DB::afterCommit(fn () => $borrower->notify(new SystemNotification($title, $message, Portal::selfServiceUrl($borrower, 'my-library'), 'ri-book-2-line', $color)));
        }
    }

    /**
     * @throws ActionFailed
     */
    private function close(BookIssue $issue, BookIssueStatus $status, CarbonInterface $on, float $fine, ?string $remarks, User $by): void
    {
        if (! $issue->isOut()) {
            throw new ActionFailed(__('This book has already been dealt with.'));
        }

        if ($on->toDateString() < $issue->issued_on->toDateString()) {
            throw new ActionFailed(__('That is before the book was issued (:date).', ['date' => $issue->issued_on->format(tenant()->setting('date_format'))]));
        }

        if ($on->toDateString() > tenant()->today()->toDateString()) {
            throw new ActionFailed(__('That date hasn\'t happened yet.'));
        }

        $fine = round(max(0, $fine), 2);

        DB::transaction(function () use ($issue, $status, $on, $fine, $remarks, $by) {
            $book = LibraryBook::query()->withTrashed()->lockForUpdate()->find($issue->library_book_id);

            $issue->update([
                'status' => $status,
                'returned_on' => $on->toDateString(),
                'received_by' => $by->id,
                'fine_amount' => $fine,
                'fine_status' => $fine > 0 ? FineStatus::Unpaid : FineStatus::None,
                'remarks' => self::clean($remarks) ?? $issue->remarks,
            ]);

            if ($book) {
                // A returned copy goes back on the shelf; a lost one leaves the library.
                $status === BookIssueStatus::Returned
                    ? $book->update(['available_quantity' => min($book->quantity, $book->available_quantity + 1)])
                    : $book->update(['quantity' => max(0, $book->quantity - 1)]);
            }

            activity('library')->performedOn($issue)->causedBy($by)
                ->withProperties(array_filter([
                    'book' => $book?->title,
                    'borrower' => $issue->borrower?->name,
                    'on' => $on->toDateString(),
                    'fine' => $fine > 0 ? $fine : null,
                ]))
                ->log($status === BookIssueStatus::Returned ? 'Book returned' : 'Book reported lost');

            if ($fine > 0) {
                $this->tell($issue,
                    __('Library fine: :amount', ['amount' => Money::school($fine)]),
                    $status === BookIssueStatus::Returned
                        ? __(':title came back :days late.', ['title' => $book?->title, 'days' => trans_choice(':count day|:count days', $issue->daysLate(), ['count' => $issue->daysLate()])])
                        : __(':title was reported lost.', ['title' => $book?->title]),
                    'warning');
            }
        });
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
