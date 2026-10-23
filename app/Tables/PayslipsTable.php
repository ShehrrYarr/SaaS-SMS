<?php

namespace App\Tables;

use App\Enums\PayrollStatus;
use App\Models\Department;
use App\Models\PayrollRecord;
use App\Support\Billing\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Salary slips, newest month first. Two views:
 *  - ['scope' => 'hr']: every approved or paid record;
 *  - ['scope' => 'self']: the signed-in staff member's paid salaries.
 */
class PayslipsTable extends Table
{
    private function self(): bool
    {
        return ($this->params['scope'] ?? null) === 'self';
    }

    public function permission(): ?string
    {
        return $this->self() ? null : 'payroll.view';
    }

    public function authorize(): void
    {
        if ($this->self()) {
            abort_unless(auth()->user()?->can('view-own-payslips'), 403);

            return;
        }

        parent::authorize();
    }

    public function query(): Builder
    {
        $query = PayrollRecord::query()->with(['staff.user', 'staff.department']);

        return $this->self()
            ? $query->where('status', PayrollStatus::Paid)->where('staff_profile_id', auth()->user()?->staffProfile()->value('id') ?? 0)
            : $query->whereIn('status', [PayrollStatus::Approved, PayrollStatus::Paid]);
    }

    public function defaultSort(): array
    {
        return ['month_year', 'desc'];
    }

    public function columns(): array
    {
        $format = tenant()?->setting('date_format') ?? 'd M Y';

        return array_values(array_filter([
            Column::make('month_year', __('Month'))->sortable()
                ->format(fn ($value, PayrollRecord $record) => new HtmlString('<span class="fw-medium text-body">'.e($record->monthLabel()).'</span>')),
            $this->self() ? null : Column::make('staff', __('Staff'))->virtual()->searchable()
                ->format(fn ($value, PayrollRecord $record) => Cells::person($record->staff?->user, collect([$record->staff?->employee_id, $record->staff?->department?->name])->filter()->implode(' · '))),
            Column::make('basic_salary', __('Basic'))->money()->hiddenOnMobile(),
            Column::make('deductions', __('Deductions'))->alignEnd()->hiddenOnMobile()
                ->format(fn ($amount) => (float) $amount > 0 ? '-'.Money::school($amount) : '—'),
            Column::make('net_salary', __('Net pay'))->sortable()->alignEnd()
                ->format(fn ($amount) => new HtmlString('<span class="fw-semibold">'.e(Money::school($amount)).'</span>')),
            Column::make('paid_on', __('Paid'))->sortable()
                ->format(fn ($value, PayrollRecord $record) => $record->paid_on
                    ? $record->paid_on->format($format)
                    : new HtmlString('<span class="badge bg-info-subtle text-info">'.e(__('Approved')).'</span>')),
        ]));
    }

    public function filters(): array
    {
        $months = collect(range(0, 11))->mapWithKeys(function (int $back) {
            $month = (tenant()?->today() ?? Carbon::today())->copy()->startOfMonth()->subMonths($back);

            return [$month->format('Y-m') => $month->translatedFormat('F Y')];
        });

        return array_values(array_filter([
            Filter::make('month_year', __('Month'))->options($months->all()),
            $this->self() ? null : Filter::make('department_id', __('Department'))
                ->options(Department::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all())
                ->query(fn (Builder $query, string $value) => $query->whereHas('staff', fn (Builder $staff) => $staff->where('department_id', (int) $value))),
        ]));
    }

    public function actions(): array
    {
        return [
            Action::make('view', __('Salary slip'))
                ->icon('ri-file-paper-2-line')
                ->url(fn (PayrollRecord $record) => route('tenant.payslips.show', $record->id), newTab: true),
            Action::make('pdf', __('Download PDF'))
                ->icon('ri-download-2-line')
                ->color('secondary')
                ->url(fn (PayrollRecord $record) => route('tenant.payslips.pdf', $record->id), newTab: true),
        ];
    }

    protected function applySearch(Builder $query, string $search): void
    {
        if ($search === '' || $this->self()) {
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
        return $this->self() ? __('No salary slips yet') : __('No salary slips');
    }

    public function emptyMessage(): ?string
    {
        return $this->self()
            ? __('Your slip appears here once your salary is marked paid.')
            : __('Slips appear once payroll is approved.');
    }
}
