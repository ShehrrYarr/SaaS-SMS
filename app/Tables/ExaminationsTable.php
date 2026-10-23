<?php

namespace App\Tables;

use App\Enums\ExamStatus;
use App\Models\AcademicYear;
use App\Models\Examination;
use App\Models\Mark;
use App\Services\Exams\ResultPublisher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Examinations, newest first.
 */
class ExaminationsTable extends Table
{
    public function permission(): ?string
    {
        return 'examinations.view';
    }

    public function query(): Builder
    {
        return Examination::query()
            ->with(['academicYear', 'term'])
            ->withCount(['schedules', 'schedules as classes_count' => fn (Builder $query) => $query->select(DB::raw('count(distinct class_id)'))]);
    }

    public function defaultSort(): array
    {
        return ['start_date', 'desc'];
    }

    public function columns(): array
    {
        $format = tenant()?->setting('date_format') ?? 'd M Y';

        return [
            Column::make('name', __('Examination'))->sortable()->searchable()
                ->format(fn (string $name, Examination $exam) => new HtmlString(
                    '<a href="'.e(route('admin.examinations.show', $exam->id)).'" wire:navigate class="fw-medium text-body">'.e($name).'</a>'
                    .'<small class="d-block text-muted">'.e(collect([$exam->academicYear?->name, $exam->term?->name])->filter()->implode(' · ')).'</small>',
                )),
            Column::make('start_date', __('Dates'))->sortable()
                ->format(fn ($value, Examination $exam) => $exam->start_date->format($format).' – '.$exam->end_date->format($format)),
            Column::make('classes_count', __('Classes'))->hiddenOnMobile()
                ->format(fn ($count, Examination $exam) => trans_choice('{0} None yet|{1} :count class|[2,*] :count classes', (int) $count, ['count' => (int) $count])
                    .' · '.trans_choice('{1} :count paper|[0,*] :count papers', $exam->schedules_count, ['count' => $exam->schedules_count])),
            Column::make('status', __('Status'))->sortable()->badge(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('academic_year_id', __('Year'))
                ->options(AcademicYear::query()->orderByDesc('start_date')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()),
            Filter::make('status', __('Status'))->options(ExamStatus::class),
        ];
    }

    public function actions(): array
    {
        return [
            Action::make('open', __('Open'))
                ->icon('ri-arrow-right-line')
                ->url(fn (Examination $exam) => route('admin.examinations.show', $exam->id)),

            Action::edit('forms.examination', 'examination', 'examinations.edit'),

            Action::make('publish', __('Publish results'))
                ->icon('ri-send-plane-line')
                ->color('success')
                ->can('examinations.publish')
                ->visible(fn (Examination $exam) => ! $exam->isPublished() && $exam->status !== ExamStatus::Draft)
                ->confirm(__('Students and parents can then see the results, and marks are locked.'), __('Publish results?'))
                ->handle(fn (Examination $exam) => app(ResultPublisher::class)->publish($exam, auth()->user()))
                ->success(__('Results published.')),

            Action::make('unpublish', __('Unpublish results'))
                ->icon('ri-arrow-go-back-line')
                ->color('warning')
                ->can('examinations.publish')
                ->visible(fn (Examination $exam) => $exam->isPublished())
                ->confirm(__('Results are hidden from students and parents, and marks can be corrected.'), __('Unpublish results?'))
                ->handle(fn (Examination $exam) => app(ResultPublisher::class)->unpublish($exam, auth()->user()))
                ->success(__('Results unpublished.')),

            Action::delete('examinations.delete')
                ->handle(function (Examination $exam) {
                    if (Mark::query()->whereIn('exam_schedule_id', $exam->schedules()->select('id'))->exists()) {
                        throw new ActionFailed(__(':name has marks, so it can\'t be deleted.', ['name' => $exam->name]));
                    }

                    $exam->schedules()->get()->each->delete();
                    $exam->delete();
                }),
        ];
    }

    public function emptyTitle(): string
    {
        return __('No examinations yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Create an examination, then set its date sheet for each class.');
    }
}
