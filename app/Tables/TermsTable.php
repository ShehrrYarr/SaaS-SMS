<?php

namespace App\Tables;

use App\Enums\AcademicPeriodStatus;
use App\Models\AcademicYear;
use App\Models\Term;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class TermsTable extends Table
{
    public function permission(): ?string
    {
        return 'terms.view';
    }

    public function query(): Builder
    {
        return Term::query()->with('academicYear');
    }

    public function defaultSort(): array
    {
        return ['start_date', 'desc'];
    }

    public function columns(): array
    {
        return [
            Column::make('name', __('Term'))->sortable()->searchable()
                ->format(fn (string $name) => new HtmlString('<span class="fw-medium text-body">'.e($name).'</span>')),
            Column::make('academicYear.name', __('Academic year'))->searchable(),
            Column::make('start_date', __('Starts'))->sortable()->date(),
            Column::make('end_date', __('Ends'))->sortable()->date(),
            Column::make('status', __('Status'))->sortable()->badge(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('academic_year_id', __('Year'))->options(
                AcademicYear::query()->latest('start_date')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()
            ),
            Filter::make('status', __('Status'))->options(AcademicPeriodStatus::class),
        ];
    }

    public function actions(): array
    {
        return [
            Action::make('make-current', __('Make current'))
                ->icon('ri-flag-2-line')
                ->color('success')
                ->can('terms.edit')
                ->visible(fn (Term $term) => ! $term->isCurrent())
                ->confirm(__('The year\'s current term will no longer be current.'), __('Make this the current term?'))
                ->handle(fn (Term $term) => $term->makeCurrent())
                ->success(fn (Term $term) => __(':name is now the current term.', ['name' => $term->name])),

            Action::edit('forms.term', 'term', 'terms.edit'),

            Action::delete('terms.delete')
                ->handle(function (Term $term) {
                    if ($term->isInUse()) {
                        throw new ActionFailed(__(':name has examinations, so it can\'t be deleted.', ['name' => $term->name]));
                    }

                    $term->delete();
                })
                ->success(fn (Term $term) => __(':name deleted.', ['name' => $term->name])),
        ];
    }

    public function bulkActions(): array
    {
        return [
            BulkAction::delete('terms.delete', fn (Term $term) => ! $term->isInUse()),
        ];
    }

    public function searchPlaceholder(): string
    {
        return __('Search terms…');
    }

    public function emptyTitle(): string
    {
        return __('No terms yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Split an academic year into terms or semesters for exams and reports.');
    }
}
