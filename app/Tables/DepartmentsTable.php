<?php

namespace App\Tables;

use App\Models\Campus;
use App\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class DepartmentsTable extends Table
{
    public function permission(): ?string
    {
        return 'departments.view';
    }

    public function query(): Builder
    {
        return Department::query()->with('campus')->withCount('staff');
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function columns(): array
    {
        return [
            Column::make('name', __('Department'))->sortable()->searchable()
                ->format(fn (string $name, Department $department) => new HtmlString(
                    '<span class="fw-medium text-body">'.e($name).'</span>'
                    .($department->code ? '<small class="d-block text-muted">'.e($department->code).'</small>' : '')
                )),
            Column::make('campus.name', __('Campus'))->format(fn (?string $name) => $name ?? __('All campuses')),
            Column::make('description', __('Description'))->hiddenOnMobile()
                ->format(fn (?string $text) => $text ? Str::limit($text, 60) : null),
            Column::make('staff_count', __('Staff'))->sortable(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('campus_id', __('Campus'))->options(
                Campus::query()->ordered()->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()
            ),
        ];
    }

    public function actions(): array
    {
        return [
            Action::edit('forms.department', 'department', 'departments.edit'),

            Action::delete('departments.delete')
                ->handle(function (Department $department) {
                    if ($department->staff()->exists()) {
                        throw new ActionFailed(__(':name has staff. Move them to another department first.', ['name' => $department->name]));
                    }

                    $department->delete();
                })
                ->success(fn (Department $department) => __(':name deleted.', ['name' => $department->name])),
        ];
    }

    public function bulkActions(): array
    {
        return [
            BulkAction::delete('departments.delete', fn (Department $department) => ! $department->staff()->exists()),
        ];
    }

    public function searchPlaceholder(): string
    {
        return __('Search departments…');
    }

    public function emptyTitle(): string
    {
        return __('No departments yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Departments group staff, e.g. Science, Languages or Administration.');
    }
}
