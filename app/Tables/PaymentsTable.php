<?php

namespace App\Tables;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Support\Billing\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Fee receipts, newest first; voided ones stay listed, marked void.
 */
class PaymentsTable extends Table
{
    public function permission(): ?string
    {
        return 'fees.view';
    }

    public function query(): Builder
    {
        return Payment::query()->with(['invoice.student.user', 'invoice.student.schoolClass', 'invoice.student.section', 'receivedBy']);
    }

    public function defaultSort(): array
    {
        return ['payment_date', 'desc'];
    }

    public function columns(): array
    {
        $format = tenant()?->setting('date_format') ?? 'd M Y';

        return [
            Column::make('receipt_number', __('Receipt'))->sortable()->searchable()
                ->format(fn (string $number, Payment $payment) => new HtmlString(
                    '<span class="fw-medium text-body">'.e($number).'</span>'
                    .($payment->isVoided() ? ' <span class="badge bg-danger-subtle text-danger">'.e(__('Void')).'</span>' : '')
                    .'<small class="d-block text-muted">'.e($payment->payment_date->format($format)).'</small>',
                )),
            Column::make('student', __('Student'))->virtual()
                ->format(fn ($value, Payment $payment) => Cells::person($payment->invoice?->student?->user, collect([$payment->invoice?->student?->admission_number, $payment->invoice?->student?->placementLabel()])->filter()->implode(' · '))),
            Column::make('invoice_id', __('Invoice'))->hiddenOnMobile()
                ->format(fn ($value, Payment $payment) => $payment->invoice ? new HtmlString(
                    '<a href="'.e(route('accounting.invoices.show', $payment->invoice_id)).'" wire:navigate>'.e($payment->invoice->invoice_number).'</a>',
                ) : null),
            Column::make('payment_method', __('Method'))->sortable()->hiddenOnMobile()
                ->format(fn (PaymentMethod $method, Payment $payment) => new HtmlString(
                    e($method->label()).($payment->transaction_id ? '<small class="d-block text-muted">'.e($payment->transaction_id).'</small>' : ''),
                )),
            Column::make('received_by', __('Received by'))->hiddenOnMobile()
                ->format(fn ($value, Payment $payment) => $payment->receivedBy?->name),
            Column::make('amount_paid', __('Amount'))->sortable()->alignEnd()
                ->format(fn ($amount, Payment $payment) => new HtmlString(
                    '<span class="'.($payment->isVoided() ? 'text-decoration-line-through text-muted' : 'fw-medium').'">'.e(Money::school($amount)).'</span>',
                )),
        ];
    }

    public function filters(): array
    {
        $months = collect(range(0, 11))->mapWithKeys(function (int $back) {
            $month = (tenant()?->today() ?? Carbon::today())->copy()->startOfMonth()->subMonths($back);

            return [$month->format('Y-m') => $month->translatedFormat('F Y')];
        });

        return [
            Filter::make('month', __('Month'))
                ->options($months->all())
                ->query(fn (Builder $query, string $value) => $query
                    ->whereDate('payment_date', '>=', Carbon::createFromFormat('!Y-m', $value)->startOfMonth())
                    ->whereDate('payment_date', '<=', Carbon::createFromFormat('!Y-m', $value)->endOfMonth())),
            Filter::make('payment_method', __('Method'))->options(PaymentMethod::class),
            Filter::make('state', __('Status'))
                ->options(['valid' => __('Valid'), 'void' => __('Void')])
                ->query(fn (Builder $query, string $value) => $value === 'void' ? $query->whereNotNull('voided_at') : $query->whereNull('voided_at')),
        ];
    }

    public function actions(): array
    {
        return [
            Action::make('receipt', __('Print receipt'))
                ->icon('ri-printer-line')
                ->color('secondary')
                ->url(fn (Payment $payment) => route('accounting.receipts.document', $payment->id), newTab: true),

            Action::make('void', __('Void receipt'))
                ->icon('ri-forbid-line')
                ->color('danger')
                ->can('fees.void')
                ->visible(fn (Payment $payment) => ! $payment->isVoided())
                ->modal('forms.void-payment', fn (Payment $payment) => ['payment' => $payment->id]),
        ];
    }

    protected function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->where(fn (Builder $query) => $query
            ->where('receipt_number', 'like', $term)
            ->orWhere('transaction_id', 'like', $term)
            ->orWhereHas('invoice', fn (Builder $invoice) => $invoice
                ->where('invoice_number', 'like', $term)
                ->orWhereHas('student', fn (Builder $student) => $student
                    ->where('admission_number', 'like', $term)
                    ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $term)))));
    }

    public function searchPlaceholder(): string
    {
        return __('Search by receipt, invoice or student…');
    }

    public function emptyTitle(): string
    {
        return __('No payments');
    }

    public function emptyMessage(): ?string
    {
        return __('Payments received at the fee counter appear here.');
    }
}
