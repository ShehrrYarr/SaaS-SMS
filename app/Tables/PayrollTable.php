<?php

namespace App\Tables;

use App\Enums\PayrollStatus;
use App\Models\Department;
use App\Models\PayrollRecord;
use App\Services\Payroll\Payroll;
use App\Support\Billing\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * One month's payroll: ['month' => 'YYYY-MM'].
 */
class PayrollTable extends Table
{
    public function permission(): ?string
    {
        return 'payroll.view';
    }

    public function query(): Builder
    {
        return PayrollRecord::query()
            ->with(['staff.user', 'staff.department'])
            ->where('month_year', (string) ($this->params['month'] ?? ''));
    }

    public function columns(): array
    {
        return [
            Column::make('staff', __('Staff'))->virtual()->searchable()
                ->format(fn ($value, PayrollRecord $record) => Cells::person($record->staff?->user, collect([$record->staff?->employee_id, $record->staff?->designation, $record->staff?->department?->name])->filter()->implode(' · '))),
            Column::make('basic_salary', __('Basic'))->sortable()->money()->hiddenOnMobile(),
            Column::make('bonuses', __('Allowances'))->alignEnd()->hiddenOnMobile()
                ->format(fn ($amount) => (float) $amount > 0 ? '+'.Money::school($amount) : '—'),
            Column::make('deductions', __('Deductions'))->alignEnd()->hiddenOnMobile()
                ->format(fn ($amount, PayrollRecord $record) => (float) $amount > 0 ? new HtmlString(
                    '<span class="text-danger">-'.e(Money::school($amount)).'</span>'
                    .'<small class="d-block text-muted">'.e(collect($record->breakdown['deductions'] ?? [])->pluck('label')->implode(', ')).'</small>',
                ) : '—'),
            Column::make('net_salary', __('Net pay'))->sortable()->alignEnd()
                ->format(fn ($amount) => new HtmlString('<span class="fw-semibold">'.e(Money::school($amount)).'</span>')),
            Column::make('status', __('Status'))->sortable()
                ->format(fn (PayrollStatus $status, PayrollRecord $record) => new HtmlString(
                    sprintf('<span class="badge bg-%1$s-subtle text-%1$s">%2$s</span>', $status->color(), e($status->label()))
                    .($record->paid_on ? '<small class="d-block text-muted mt-1">'.e($record->paid_on->format(tenant()->setting('date_format')).' · '.$record->payment_method?->label()).'</small>' : ''),
                )),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('status', __('Status'))->options(PayrollStatus::class),
            Filter::make('department_id', __('Department'))
                ->options(Department::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all())
                ->query(fn (Builder $query, string $value) => $query->whereHas('staff', fn (Builder $staff) => $staff->where('department_id', (int) $value))),
        ];
    }

    public function actions(): array
    {
        $payroll = app(Payroll::class);
        $draft = fn (PayrollRecord $record) => $record->isDraft();

        return [
            Action::make('edit', __('Allowances & deductions'))
                ->icon('ri-pencil-line')
                ->can('payroll.generate')
                ->visible($draft)
                ->modal('forms.payroll-record', fn (PayrollRecord $record) => ['record' => $record->id], 'lg'),

            Action::make('recalculate', __('Recalculate from attendance'))
                ->icon('ri-refresh-line')
                ->color('secondary')
                ->can('payroll.generate')
                ->visible($draft)
                ->handle(fn (PayrollRecord $record) => $payroll->recalculate($record))
                ->success(__('Recalculated.')),

            Action::make('approve', __('Approve'))
                ->icon('ri-check-line')
                ->color('success')
                ->can('payroll.approve')
                ->visible($draft)
                ->handle(fn (PayrollRecord $record) => $payroll->approve($record, auth()->user()))
                ->success(__('Approved.')),

            Action::make('pay', __('Mark paid'))
                ->icon('ri-money-dollar-circle-line')
                ->color('success')
                ->can('payroll.pay')
                ->visible(fn (PayrollRecord $record) => $record->status === PayrollStatus::Approved)
                ->modal('forms.payroll-payment', fn (PayrollRecord $record) => ['record' => $record->id]),

            Action::make('reopen', __('Back to draft'))
                ->icon('ri-arrow-go-back-line')
                ->color('warning')
                ->can('payroll.approve')
                ->visible(fn (PayrollRecord $record) => $record->status === PayrollStatus::Approved)
                ->handle(fn (PayrollRecord $record) => $payroll->reopen($record, auth()->user()))
                ->success(__('Back to draft.')),

            Action::make('unpay', __('Undo payment'))
                ->icon('ri-arrow-go-back-line')
                ->color('warning')
                ->can('payroll.pay')
                ->visible(fn (PayrollRecord $record) => $record->isPaid())
                ->confirm(__('The salary goes back to approved, and its expense entry is removed.'), __('Undo this payment?'))
                ->handle(fn (PayrollRecord $record) => $payroll->undoPayment($record, auth()->user()))
                ->success(__('Payment undone.')),

            Action::make('payslip', __('Salary slip'))
                ->icon('ri-file-paper-2-line')
                ->color('secondary')
                ->url(fn (PayrollRecord $record) => route('tenant.payslips.show', $record->id), newTab: true),

            Action::make('discard', __('Discard draft'))
                ->icon('ri-delete-bin-line')
                ->color('danger')
                ->can('payroll.generate')
                ->visible($draft)
                ->confirm(__('Generate the month again to recreate it.'), __('Discard this draft?'))
                ->handle(fn (PayrollRecord $record) => $payroll->discard($record))
                ->success(__('Draft discarded.')),
        ];
    }

    public function bulkActions(): array
    {
        $payroll = app(Payroll::class);

        return [
            BulkAction::make('approve', __('Approve'))
                ->icon('ri-check-line')
                ->color('success')
                ->can('payroll.approve')
                ->confirm(__('Approve the selected drafts? They can no longer be edited.'))
                ->handle(function (Collection $rows) use ($payroll): int {
                    $drafts = $rows->filter(fn (PayrollRecord $record) => $record->isDraft());
                    $drafts->each(fn (PayrollRecord $record) => $payroll->approve($record, auth()->user()));

                    return $drafts->count();
                })
                ->success(fn (int $count) => trans_choice(':count record approved.|:count records approved.', $count, ['count' => $count])),
        ];
    }

    protected function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->whereHas('staff', fn (Builder $staff) => $staff
            ->where('employee_id', 'like', $term)
            ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $term)));
    }

    public function searchPlaceholder(): string
    {
        return __('Search by staff name or ID…');
    }

    public function emptyTitle(): string
    {
        return __('No payroll for this month yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Generate the month to create a draft for every staff member with a salary.');
    }
}
