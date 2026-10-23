<?php

namespace App\Tables;

use App\Enums\SubjectType;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class SubjectsTable extends Table
{
    public function permission(): ?string
    {
        return 'subjects.view';
    }

    public function query(): Builder
    {
        return Subject::query()->withCount(['allocations as classes_count' => fn (Builder $query) => $query->select(DB::raw('count(distinct class_id)'))]);
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function columns(): array
    {
        return [
            Column::make('code', __('Code'))->sortable()->searchable()
                ->format(fn (string $code) => new HtmlString('<span class="badge bg-light text-body border fw-medium">'.e($code).'</span>')),
            Column::make('name', __('Subject'))->sortable()->searchable()
                ->format(fn (string $name) => new HtmlString('<span class="fw-medium text-body">'.e($name).'</span>')),
            Column::make('type', __('Type'))->sortable()->badge(),
            Column::make('classes_count', __('Classes'))->sortable(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('type', __('Type'))->options(SubjectType::class),
        ];
    }

    public function actions(): array
    {
        return [
            Action::edit('forms.subject', 'subject', 'subjects.edit'),

            Action::delete('subjects.delete')
                ->handle(function (Subject $subject) {
                    if ($subject->allocations()->exists()) {
                        throw new ActionFailed(__(':name is taught in some classes. Remove it from them in Subject allocation first.', ['name' => $subject->name]));
                    }

                    $subject->delete();
                })
                ->success(fn (Subject $subject) => __(':name deleted.', ['name' => $subject->name])),
        ];
    }

    public function bulkActions(): array
    {
        return [
            BulkAction::delete('subjects.delete', fn (Subject $subject) => ! $subject->allocations()->exists()),
        ];
    }

    public function searchPlaceholder(): string
    {
        return __('Search by code or name…');
    }

    public function emptyTitle(): string
    {
        return __('No subjects yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Add the subjects your school teaches, then allocate them to classes.');
    }
}
