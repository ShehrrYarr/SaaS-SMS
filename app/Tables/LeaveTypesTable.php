<?php

namespace App\Tables;

use App\Enums\LeaveAppliesTo;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kinds of leave for staff and students.
 */
class LeaveTypesTable extends Table
{
    public function permission(): ?string
    {
        return 'leave.manage';
    }

    public function query(): Builder
    {
        return LeaveType::query()->withCount('requests');
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function columns(): array
    {
        return [
            Column::make('name', __('Name'))->sortable()->searchable()
                ->format(fn (string $name, LeaveType $type) => $type->code ? $name.' ('.$type->code.')' : $name),
            Column::make('applies_to', __('For'))->sortable(),
            Column::make('days_per_year', __('Days a year'))->sortable()
                ->format(fn (?int $days) => $days === null ? __('No limit') : (string) $days),
            Column::make('is_paid', __('Paid'))->boolean()->hiddenOnMobile(),
            Column::make('requires_attachment', __('Needs a document'))->boolean()->hiddenOnMobile(),
            Column::make('requests_count', __('Requests'))->sortable()->hiddenOnMobile(),
            Column::make('is_active', __('Active'))->boolean(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('applies_to', __('For'))->options(LeaveAppliesTo::class),
        ];
    }

    public function actions(): array
    {
        return [
            Action::edit('forms.leave-type', 'leaveType', 'leave.manage'),
            Action::delete('leave.manage', __('Leave types that have been used can\'t be deleted; switch them off instead.'))
                ->handle(function (LeaveType $type) {
                    if ($type->requests()->withTrashed()->exists()) {
                        throw new ActionFailed(__(':name has been used, so it can\'t be deleted. Switch it off instead.', ['name' => $type->name]));
                    }

                    $type->delete();
                }),
        ];
    }

    public function emptyTitle(): string
    {
        return __('No leave types');
    }
}
