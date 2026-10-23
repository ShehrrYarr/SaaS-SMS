<?php

namespace App\Tables;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\SchoolClass;
use App\Services\Fees\FeeSchedule;
use App\Support\Billing\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Invoices, newest first. Params:
 *  - ['student' => id]: one student's invoices (student profile);
 *  - ['batch' => id]: one bulk run's invoices.
 */
class InvoicesTable extends Table
{
    public function permission(): ?string
    {
        return 'invoices.view';
    }

    public function query(): Builder
    {
        return Invoice::query()
            ->with(['student.user', 'student.schoolClass', 'student.section'])
            ->when($this->params['student'] ?? null, fn (Builder $query, $student) => $query->where('student_profile_id', (int) $student))
            ->when($this->params['batch'] ?? null, fn (Builder $query, $batch) => $query->where('invoice_batch_id', (int) $batch));
    }

    public function defaultSort(): array
    {
        return ['issue_date', 'desc'];
    }

    public function columns(): array
    {
        $format = tenant()?->setting('date_format') ?? 'd M Y';
        $today = tenant()?->today();
        $oneStudent = isset($this->params['student']);

        return array_values(array_filter([
            Column::make('invoice_number', __('Invoice'))->sortable()->searchable()
                ->format(fn (string $number, Invoice $invoice) => new HtmlString(
                    '<a href="'.e(route('accounting.invoices.show', $invoice->id)).'" wire:navigate class="fw-medium text-body">'.e($number).'</a>'
                    .'<small class="d-block text-muted">'.e($invoice->periodLabel() ?? $invoice->issue_date->format($format)).'</small>',
                )),
            $oneStudent ? null : Column::make('student', __('Student'))->virtual()
                ->format(fn ($value, Invoice $invoice) => Cells::person($invoice->student?->user, collect([$invoice->student?->admission_number, $invoice->student?->placementLabel()])->filter()->implode(' · '))),
            Column::make('due_date', __('Due'))->sortable()
                ->format(fn ($value, Invoice $invoice) => new HtmlString(
                    '<span class="'.($invoice->isOpen() && $today && $invoice->due_date->lt($today) ? 'text-danger' : '').' text-nowrap">'.e($invoice->due_date->format($format)).'</span>',
                )),
            Column::make('total_amount', __('Total'))->sortable()->money(),
            Column::make('balance', __('Balance'))->virtual()->alignEnd()
                ->format(fn ($value, Invoice $invoice) => $invoice->isCancelled() ? null : new HtmlString(
                    '<span class="'.($invoice->balanceAmount() > 0 ? 'fw-medium' : 'text-muted').'">'.e(Money::school($invoice->balanceAmount())).'</span>',
                )),
            Column::make('status', __('Status'))->sortable()->badge(),
        ]));
    }

    public function filters(): array
    {
        $periods = Invoice::query()->whereNotNull('billing_period')->distinct()->orderByDesc('billing_period')->limit(24)->pluck('billing_period');

        return array_values(array_filter([
            Filter::make('status', __('Status'))
                ->options(['open' => __('Not fully paid')] + InvoiceStatus::options())
                ->query(fn (Builder $query, string $value) => $value === 'open' ? $query->open() : $query->where('status', $value)),
            Filter::make('billing_period', __('Month'))
                ->options($periods->mapWithKeys(fn (string $period) => [$period => FeeSchedule::periodLabel($period)])->all()),
            isset($this->params['student']) ? null : Filter::make('class_id', __('Class'))
                ->options(SchoolClass::query()->ordered()->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all())
                ->query(fn (Builder $query, string $value) => $query->whereHas('student', fn (Builder $student) => $student->where('class_id', (int) $value))),
        ]));
    }

    public function actions(): array
    {
        return [
            Action::make('open', __('Open'))
                ->icon('ri-arrow-right-line')
                ->url(fn (Invoice $invoice) => route('accounting.invoices.show', $invoice->id))
                ->can(fn ($user) => $user->canAccessPortal('accounting')),

            Action::make('collect', __('Receive payment'))
                ->icon('ri-hand-coin-line')
                ->color('success')
                ->can('fees.collect')
                ->visible(fn (Invoice $invoice) => $invoice->isOpen())
                ->modal('forms.payment', fn (Invoice $invoice) => ['invoice' => $invoice->id]),

            Action::make('print', __('Print'))
                ->icon('ri-printer-line')
                ->color('secondary')
                ->url(fn (Invoice $invoice) => route('accounting.invoices.document', $invoice->id), newTab: true)
                ->visible(fn (Invoice $invoice) => ! $invoice->isCancelled()),

            Action::make('cancel', __('Cancel invoice'))
                ->icon('ri-close-circle-line')
                ->color('danger')
                ->can('invoices.cancel')
                ->visible(fn (Invoice $invoice) => ! $invoice->isCancelled() && (float) $invoice->paid_amount <= 0)
                ->modal('forms.cancel-invoice', fn (Invoice $invoice) => ['invoice' => $invoice->id]),
        ];
    }

    protected function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->where(fn (Builder $query) => $query
            ->where('invoice_number', 'like', $term)
            ->orWhereHas('student', fn (Builder $student) => $student
                ->where('admission_number', 'like', $term)
                ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $term))));
    }

    public function searchPlaceholder(): string
    {
        return __('Search by invoice number or student…');
    }

    public function emptyTitle(): string
    {
        return __('No invoices');
    }

    public function emptyMessage(): ?string
    {
        return isset($this->params['student'])
            ? __('No invoices have been raised for this student yet.')
            : __('Raise invoices one at a time, or for whole classes with bulk generation.');
    }
}
