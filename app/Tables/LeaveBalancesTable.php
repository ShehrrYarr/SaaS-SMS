<?php

namespace App\Tables;

use App\Models\Department;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Staff leave balances for one academic year: ['year' => id].
 */
class LeaveBalancesTable extends Table
{
    public function permission(): ?string
    {
        return 'leave.view';
    }

    public function query(): Builder
    {
        return LeaveBalance::query()
            ->with(['staff.user', 'staff.department', 'leaveType'])
            ->where('academic_year_id', (int) ($this->params['year'] ?? 0))
            ->whereHas('staff')
            ->whereHas('leaveType', fn (Builder $query) => $query->whereNotNull('days_per_year'));
    }

    public function columns(): array
    {
        $days = fn ($value) => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');

        return [
            Column::make('staff', __('Staff'))->virtual()->searchable()
                ->format(fn ($value, LeaveBalance $balance) => Cells::person($balance->staff?->user, collect([$balance->staff?->employee_id, $balance->staff?->department?->name])->filter()->implode(' · '))),
            Column::make('leave_type_id', __('Leave type'))
                ->format(fn ($value, LeaveBalance $balance) => $balance->leaveType?->name),
            Column::make('allocated', __('Allowed'))->sortable()->alignEnd()->format($days),
            Column::make('used', __('Taken'))->sortable()->alignEnd()->format($days),
            Column::make('remaining', __('Left'))->virtual()->alignEnd()
                ->format(fn ($value, LeaveBalance $balance) => new HtmlString(
                    '<span class="fw-medium '.($balance->remaining <= 0 ? 'text-danger' : '').'">'.e($days($balance->remaining)).'</span>',
                )),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('leave_type_id', __('Leave type'))
                ->options(LeaveType::query()->whereNotNull('days_per_year')->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()),
            Filter::make('department_id', __('Department'))
                ->options(Department::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all())
                ->query(fn (Builder $query, string $value) => $query->whereHas('staff', fn (Builder $staff) => $staff->where('department_id', (int) $value))),
        ];
    }

    public function actions(): array
    {
        return [
            Action::make('edit', __('Change allowance'))
                ->icon('ri-pencil-line')
                ->can('leave.manage')
                ->modal('forms.leave-balance', fn (LeaveBalance $balance) => ['balance' => $balance->id]),
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
        return __('No leave balances');
    }

    public function emptyMessage(): ?string
    {
        return __('Balances appear for leave types with a yearly limit (Leave types → Days a year).');
    }
}
