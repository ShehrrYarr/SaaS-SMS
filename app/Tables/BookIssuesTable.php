<?php

namespace App\Tables;

use App\Enums\BookIssueStatus;
use App\Enums\FineStatus;
use App\Enums\UserType;
use App\Models\BookIssue;
use App\Models\User;
use App\Services\Library\Library;
use App\Support\Billing\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Library loans. Params:
 *  - ['scope' => 'loans']: books out now (default), with return, renew and lost;
 *  - ['scope' => 'history']: books returned or lost;
 *  - ['scope' => 'fines']: loans with a fine, settled or not;
 *  - ['user' => id]: one borrower's loans (any scope).
 */
class BookIssuesTable extends Table
{
    public function permission(): ?string
    {
        return 'library.view';
    }

    private function scope(): string
    {
        return in_array($this->params['scope'] ?? null, ['loans', 'history', 'fines'], true) ? $this->params['scope'] : 'loans';
    }

    public function query(): Builder
    {
        return BookIssue::query()
            ->with([
                'book' => fn ($query) => $query->withTrashed(),
                'borrower' => fn ($query) => $query->withTrashed()->with(['studentProfile.schoolClass', 'studentProfile.section', 'staffProfile']),
                'invoice',
            ])
            ->when($this->params['user'] ?? null, fn (Builder $query, $user) => $query->where('user_id', (int) $user))
            ->when($this->scope() === 'loans', fn (Builder $query) => $query->out())
            ->when($this->scope() === 'history', fn (Builder $query) => $query->where('status', '!=', BookIssueStatus::Issued))
            ->when($this->scope() === 'fines', fn (Builder $query) => $query->where('fine_status', '!=', FineStatus::None));
    }

    public function defaultSort(): array
    {
        return match ($this->scope()) {
            'loans' => ['due_on', 'asc'],
            default => ['returned_on', 'desc'],
        };
    }

    public function columns(): array
    {
        $format = tenant()?->setting('date_format') ?? 'd M Y';
        $today = tenant()?->today();
        $oneBorrower = isset($this->params['user']);

        return array_values(array_filter([
            Column::make('book', __('Book'))->virtual()->searchable('book.title')
                ->format(fn ($value, BookIssue $issue) => new HtmlString(
                    '<span class="fw-medium text-body d-block">'.e($issue->book?->title).'</span>'
                    .($issue->book?->author ? '<small class="text-muted">'.e($issue->book->author).'</small>' : ''),
                )),
            $oneBorrower ? null : Column::make('borrower', __('Borrower'))->virtual()->searchable('borrower.name')
                ->format(fn ($value, BookIssue $issue) => Cells::borrower($issue->borrower)),
            Column::make('issued_on', __('Issued'))->sortable()->date()->hiddenOnMobile(),
            Column::make('due_on', __('Due'))->sortable()
                ->format(function ($value, BookIssue $issue) use ($format, $today) {
                    $late = $issue->daysLate($today);
                    $note = match (true) {
                        $issue->isOut() && $late > 0 => '<small class="d-block text-danger">'.e(trans_choice(':count day overdue|:count days overdue', $late, ['count' => $late])).'</small>',
                        $issue->isOut() && $issue->renewals > 0 => '<small class="d-block text-muted">'.e(trans_choice('Renewed :count time|Renewed :count times', $issue->renewals, ['count' => $issue->renewals])).'</small>',
                        default => '',
                    };

                    return new HtmlString('<span class="text-nowrap '.($issue->isOverdue($today) ? 'text-danger fw-medium' : '').'">'.e($issue->due_on->format($format)).'</span>'.$note);
                }),
            $this->scope() === 'loans' ? null : Column::make('returned_on', __('Back'))->sortable()
                ->format(fn ($value, BookIssue $issue) => new HtmlString(
                    ($issue->returned_on ? '<span class="text-nowrap">'.e($issue->returned_on->format($format)).'</span>' : '<span class="text-muted">—</span>')
                    .'<span class="d-block"><span class="badge bg-'.$issue->status->color().'-subtle text-'.$issue->status->color().'">'.e($issue->status->label()).'</span></span>',
                )),
            $this->scope() === 'loans' ? null : Column::make('fine_amount', __('Fine'))->sortable()->alignEnd()
                ->format(fn ($value, BookIssue $issue) => $issue->fine_status === FineStatus::None ? null : new HtmlString(
                    '<span class="fw-medium">'.e(Money::school($issue->fine_amount)).'</span>'
                    .'<span class="d-block"><span class="badge bg-'.$issue->fine_status->color().'-subtle text-'.$issue->fine_status->color().'">'.e($issue->fine_status->label()).'</span>'
                    .($issue->invoice ? ' <small class="text-muted">'.e($issue->invoice->invoice_number).'</small>' : '').'</span>',
                )),
        ]));
    }

    public function filters(): array
    {
        $filters = isset($this->params['user']) ? [] : [
            Filter::make('borrower_type', __('Borrower'))
                ->options(['student' => __('Students'), 'staff' => __('Staff')])
                ->query(fn (Builder $query, string $value) => $query->whereHas('borrower', fn (Builder $user) => $value === 'student'
                    ? $user->where('user_type', UserType::Student)
                    : $user->where('user_type', '!=', UserType::Student))),
        ];

        if ($this->scope() === 'loans') {
            $filters[] = Filter::make('due', __('Due'))
                ->options(['overdue' => __('Overdue'), 'week' => __('Due within a week')])
                ->query(fn (Builder $query, string $value) => $value === 'overdue'
                    ? $query->overdue()
                    : $query->whereDate('due_on', '>=', tenant()->today()->toDateString())->whereDate('due_on', '<=', tenant()->today()->addDays(7)->toDateString()));
        }

        if ($this->scope() === 'history') {
            $filters[] = Filter::make('status', __('Outcome'))->options([
                BookIssueStatus::Returned->value => BookIssueStatus::Returned->label(),
                BookIssueStatus::Lost->value => BookIssueStatus::Lost->label(),
            ]);
        }

        if ($this->scope() === 'fines') {
            $filters[] = Filter::make('fine_status', __('Fine'))->options(array_diff_key(FineStatus::options(), [FineStatus::None->value => true]));
        }

        return $filters;
    }

    public function actions(): array
    {
        return [
            Action::make('return', __('Return'))
                ->icon('ri-arrow-go-back-line')
                ->color('success')
                ->modal('forms.book-return', fn (BookIssue $issue) => ['issue' => $issue->id])
                ->can('library.issue')
                ->visible(fn (BookIssue $issue) => $issue->isOut()),

            Action::make('renew', __('Renew'))
                ->icon('ri-refresh-line')
                ->can('library.issue')
                ->visible(fn (BookIssue $issue) => $issue->isOut())
                ->confirm(__('Lend it for another loan period from today?'), __('Renew this loan?'))
                ->handle(fn (BookIssue $issue) => app(Library::class)->renew($issue, auth()->user()))
                ->success(fn (BookIssue $issue) => __('Renewed: now due :date.', ['date' => $issue->fresh()->due_on->format(tenant()->setting('date_format'))])),

            Action::make('lost', __('Report lost'))
                ->icon('ri-error-warning-line')
                ->color('danger')
                ->modal('forms.book-return', fn (BookIssue $issue) => ['issue' => $issue->id, 'lost' => true])
                ->can('library.issue')
                ->visible(fn (BookIssue $issue) => $issue->isOut()),

            Action::make('paid', __('Fine paid'))
                ->icon('ri-hand-coin-line')
                ->color('success')
                ->can('library.issue')
                ->visible(fn (BookIssue $issue) => $issue->hasFineOwed())
                ->confirm(__('Record that the fine was paid at the library?'), __('Fine paid?'))
                ->handle(fn (BookIssue $issue) => app(Library::class)->settleFine($issue, FineStatus::Paid, auth()->user()))
                ->success(__('Fine marked as paid.')),

            Action::make('bill', __('Add to fees'))
                ->icon('ri-bill-line')
                ->can(fn (User $user, BookIssue $issue) => $user->can('library.issue') && $user->can('invoices.create'))
                ->visible(fn (BookIssue $issue) => $issue->hasFineOwed() && $issue->borrower?->user_type === UserType::Student)
                ->confirm(__('Raise a fee invoice for this fine, to be collected with the student\'s fees?'), __('Add the fine to fees?'))
                ->handle(fn (BookIssue $issue) => app(Library::class)->billFine($issue, auth()->user()))
                ->success(fn (BookIssue $issue) => __('Invoice :number raised for the fine.', ['number' => $issue->fresh('invoice')->invoice?->invoice_number])),

            Action::make('waive', __('Waive fine'))
                ->icon('ri-close-circle-line')
                ->color('secondary')
                ->can('library.manage')
                ->visible(fn (BookIssue $issue) => $issue->hasFineOwed())
                ->confirm(__('The borrower won\'t have to pay it.'), __('Waive this fine?'))
                ->handle(fn (BookIssue $issue) => app(Library::class)->settleFine($issue, FineStatus::Waived, auth()->user()))
                ->success(__('Fine waived.')),
        ];
    }

    public function searchPlaceholder(): string
    {
        return isset($this->params['user']) ? __('Search by title…') : __('Search by title or borrower…');
    }

    public function emptyTitle(): string
    {
        return match ($this->scope()) {
            'loans' => __('No books out on loan'),
            'fines' => __('No library fines'),
            default => __('No returned books yet'),
        };
    }

    public function emptyMessage(): ?string
    {
        return $this->scope() === 'loans' ? __('Books you issue to students and staff appear here until they come back.') : null;
    }
}
