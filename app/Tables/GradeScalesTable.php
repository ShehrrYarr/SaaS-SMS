<?php

namespace App\Tables;

use App\Models\GradeScale;
use App\Models\GradeScaleItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Grade scales: how percentages become grades on marks and report cards.
 */
class GradeScalesTable extends Table
{
    public function permission(): ?string
    {
        return 'grade-scales.view';
    }

    public function query(): Builder
    {
        return GradeScale::query()->with('items')->withCount('examinations');
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function columns(): array
    {
        return [
            Column::make('name', __('Name'))->sortable()->searchable()
                ->format(fn (string $name, GradeScale $scale) => new HtmlString(
                    '<span class="fw-medium text-body">'.e($name).'</span>'
                    .($scale->is_default ? ' <span class="badge bg-primary-subtle text-primary ms-1">'.e(__('Default')).'</span>' : ''),
                )),
            Column::make('items', __('Grades'))->virtual()
                ->format(fn ($value, GradeScale $scale) => new HtmlString($scale->items
                    ->map(fn (GradeScaleItem $item) => '<span class="badge bg-light text-body border me-1 mb-1">'.e($item->grade).' <span class="text-muted">≥ '.e(rtrim(rtrim((string) $item->min_percentage, '0'), '.')).'%</span></span>')
                    ->implode(''))),
            Column::make('examinations_count', __('Used by'))->sortable()->hiddenOnMobile()
                ->format(fn (int $count) => trans_choice('{0} No exams|{1} :count exam|[2,*] :count exams', $count, ['count' => $count])),
        ];
    }

    public function actions(): array
    {
        return [
            Action::make('default', __('Make default'))
                ->icon('ri-star-line')
                ->can('grade-scales.manage')
                ->visible(fn (GradeScale $scale) => ! $scale->is_default)
                ->handle(fn (GradeScale $scale) => DB::transaction(function () use ($scale) {
                    GradeScale::query()->where('is_default', true)->get()->each->update(['is_default' => false]);
                    $scale->update(['is_default' => true]);
                }))
                ->success(fn (GradeScale $scale) => __(':name is now the default scale.', ['name' => $scale->name])),

            Action::edit('forms.grade-scale', 'gradeScale', 'grade-scales.manage')
                ->modal('forms.grade-scale', fn (GradeScale $scale) => ['gradeScale' => $scale->id], 'lg'),

            Action::delete('grade-scales.manage')
                ->handle(function (GradeScale $scale) {
                    if ($scale->is_default) {
                        throw new ActionFailed(__('The default scale can\'t be deleted. Make another scale the default first.'));
                    }

                    if ($scale->examinations()->exists()) {
                        throw new ActionFailed(__(':name is used by examinations, so it can\'t be deleted.', ['name' => $scale->name]));
                    }

                    $scale->delete();
                }),
        ];
    }

    public function emptyTitle(): string
    {
        return __('No grade scales');
    }
}
