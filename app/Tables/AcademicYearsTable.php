<?php

namespace App\Tables;

use App\Enums\AcademicPeriodStatus;
use App\Models\AcademicYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class AcademicYearsTable extends Table
{
    public function permission(): ?string
    {
        return 'academic-years.view';
    }

    public function query(): Builder
    {
        return AcademicYear::query()->withCount('terms');
    }

    public function defaultSort(): array
    {
        return ['start_date', 'desc'];
    }

    public function columns(): array
    {
        return [
            Column::make('name', __('Name'))
                ->sortable()
                ->searchable()
                ->format(fn (string $name) => new HtmlString('<span class="fw-medium text-body">'.e($name).'</span>')),
            Column::make('start_date', __('Starts'))->sortable()->date(),
            Column::make('end_date', __('Ends'))->sortable()->date(),
            Column::make('terms_count', __('Terms'))
                ->sortable(fn (Builder $query, string $direction) => $query->orderBy('terms_count', $direction))
                ->hiddenOnMobile(),
            Column::make('status', __('Status'))->sortable()->badge(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('status', __('Status'))->options(AcademicPeriodStatus::class),
        ];
    }

    public function actions(): array
    {
        return [
            Action::make('make-current', __('Make current'))
                ->icon('ri-flag-2-line')
                ->color('success')
                ->can('academic-years.edit')
                ->visible(fn (AcademicYear $year) => ! $year->isCurrent())
                ->confirm(__('Whichever year is current now will be archived.'), __('Make this the current academic year?'))
                ->handle(fn (AcademicYear $year) => $year->makeCurrent())
                ->success(fn (AcademicYear $year) => __(':name is now the current academic year.', ['name' => $year->name])),

            Action::edit('forms.academic-year', 'academicYear', 'academic-years.edit'),

            Action::delete('academic-years.delete', __('Its terms will be deleted as well.'))
                ->handle(function (AcademicYear $year) {
                    if ($year->isCurrent()) {
                        throw new ActionFailed(__('The current academic year can\'t be deleted. Make another year current first.'));
                    }

                    if ($year->isInUse()) {
                        throw new ActionFailed(__(':name has enrollments or examinations, so it can\'t be deleted.', ['name' => $year->name]));
                    }

                    $year->delete();
                })
                ->success(fn (AcademicYear $year) => __(':name deleted.', ['name' => $year->name])),
        ];
    }

    public function bulkActions(): array
    {
        return [
            BulkAction::delete('academic-years.delete', fn (AcademicYear $year) => ! $year->isCurrent() && ! $year->isInUse()),
        ];
    }

    public function searchPlaceholder(): string
    {
        return __('Search academic years…');
    }

    public function emptyTitle(): string
    {
        return __('No academic years yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Add your first academic year to start organising terms, classes and exams.');
    }
}
