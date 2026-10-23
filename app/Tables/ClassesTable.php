<?php

namespace App\Tables;

use App\Enums\StudentStatus;
use App\Models\Campus;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ClassesTable extends Table
{
    public function permission(): ?string
    {
        return 'classes.view';
    }

    public function query(): Builder
    {
        return SchoolClass::query()
            ->with(['campus', 'sections' => fn ($query) => $query->ordered()])
            ->withCount(['students' => fn (Builder $query) => $query->where('status', StudentStatus::Active)]);
    }

    public function defaultSort(): array
    {
        return ['numeric_level', 'asc'];
    }

    public function columns(): array
    {
        $multipleCampuses = Campus::query()->count() > 1;

        return array_values(array_filter([
            Column::make('name', __('Class'))->sortable(fn (Builder $query, string $direction) => $query->orderBy('name', $direction))->searchable()
                ->format(fn (string $name, SchoolClass $class) => new HtmlString(
                    '<span class="fw-medium text-body">'.e($name).'</span>'
                    .($class->code ? '<small class="d-block text-muted">'.e($class->code).'</small>' : '')
                )),
            Column::make('numeric_level', __('Level'))
                ->sortable(fn (Builder $query, string $direction) => $direction === 'asc' ? $query->ordered() : $query->orderByDesc('numeric_level')->orderByDesc('name')),
            $multipleCampuses ? Column::make('campus.name', __('Campus'))->hiddenOnMobile() : null,
            Column::make('sections', __('Sections'))
                ->format(fn ($sections) => $sections->isEmpty()
                    ? new HtmlString('<span class="text-muted">'.e(__('None')).'</span>')
                    : new HtmlString($sections->map(fn ($section) => '<span class="badge bg-primary-subtle text-primary me-1">'.e($section->name).'</span>')->implode(''))),
            Column::make('students_count', __('Students'))->sortable()
                ->format(fn (int $count, SchoolClass $class) => $class->capacity ? "{$count} / {$class->capacity}" : (string) $count),
        ]));
    }

    public function filters(): array
    {
        $campuses = Campus::query()->ordered()->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all();

        return count($campuses) > 1 ? [Filter::make('campus_id', __('Campus'))->options($campuses)] : [];
    }

    public function actions(): array
    {
        return [
            Action::edit('forms.school-class', 'schoolClass', 'classes.edit')
                ->modal('forms.school-class', fn (SchoolClass $class) => ['schoolClass' => $class->id], 'lg'),

            Action::delete('classes.delete', __('Its sections and subject allocations are deleted too.'))
                ->handle(function (SchoolClass $class) {
                    if ($class->isInUse()) {
                        throw new ActionFailed(__(':name has students, so it can\'t be deleted.', ['name' => $class->name]));
                    }

                    $class->delete();
                })
                ->success(fn (SchoolClass $class) => __(':name deleted.', ['name' => $class->name])),
        ];
    }

    public function searchPlaceholder(): string
    {
        return __('Search classes…');
    }

    public function emptyTitle(): string
    {
        return __('No classes yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Create classes (grades) and their sections, e.g. Grade 1 with sections A and B.');
    }
}
