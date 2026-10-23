<?php

namespace App\Tables;

use App\Enums\HomeworkStatus;
use App\Enums\SubmissionStatus;
use App\Models\Homework;
use App\Models\SubjectClass;
use App\Models\User;
use App\Services\Homework\Homeworks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Teacher portal: homework on the allocations I teach (all of it with
 * homework.manage), latest due first.
 */
class HomeworkTable extends Table
{
    public function permission(): ?string
    {
        return 'homework.view';
    }

    public function query(): Builder
    {
        return app(Homeworks::class)->visibleTo(auth()->user())
            ->with(['subjectClass.subject', 'subjectClass.schoolClass', 'subjectClass.section', 'section'])
            ->withCount([
                'submissions as handed_in_count' => fn (Builder $query) => $query->whereIn('status', [SubmissionStatus::Submitted, SubmissionStatus::Late]),
                'submissions as graded_count' => fn (Builder $query) => $query->where('status', SubmissionStatus::Graded),
            ]);
    }

    public function defaultSort(): array
    {
        return ['due_at', 'desc'];
    }

    public function columns(): array
    {
        return [
            Column::make('title', __('Homework'))->sortable()->searchable()
                ->format(fn (string $title, Homework $homework) => new HtmlString(
                    '<a href="'.e(route('teacher.homework.show', $homework->id)).'" wire:navigate class="fw-medium text-body">'.e($title).'</a>'
                    .'<small class="d-block text-muted">'.e($homework->groupLabel()).'</small>',
                )),
            Column::make('due_at', __('Due'))->sortable()
                ->format(fn ($value, Homework $homework) => new HtmlString(
                    '<span class="text-nowrap '.($homework->isPublished() && $homework->isPastDue() ? 'text-danger' : '').'">'.e($homework->dueLabel()).'</span>',
                )),
            Column::make('work', __('Handed in'))->virtual()->hiddenOnMobile()
                ->format(fn ($value, Homework $homework) => $homework->isDraft() ? '—' : new HtmlString(
                    '<span class="fw-medium">'.e(__(':count to grade', ['count' => $homework->handed_in_count])).'</span>'
                    .'<small class="d-block text-muted">'.e(__(':count graded', ['count' => $homework->graded_count])).'</small>',
                )),
            Column::make('status', __('Status'))->sortable()->badge(),
        ];
    }

    public function filters(): array
    {
        $allocations = app(Homeworks::class)->allocationsFor(auth()->user())
            ->mapWithKeys(fn (SubjectClass $allocation) => [
                (string) $allocation->id => $allocation->schoolClass->name.($allocation->section ? ' · '.$allocation->section->name : '').' — '.$allocation->subject->name,
            ]);

        return [
            Filter::make('status', __('Status'))->options(HomeworkStatus::options()),
            Filter::make('subject_class_id', __('Class & subject'))->options($allocations->all()),
        ];
    }

    public function actions(): array
    {
        $looksAfter = fn (string $permission) => fn (User $user, Homework $homework) => $user->can($permission) && app(Homeworks::class)->looksAfter($user, $homework);

        return [
            Action::make('open', __('Open'))
                ->icon('ri-arrow-right-line')
                ->url(fn (Homework $homework) => route('teacher.homework.show', $homework->id)),

            Action::make('publish', __('Publish'))
                ->icon('ri-send-plane-line')
                ->color('success')
                ->can($looksAfter('homework.edit'))
                ->visible(fn (Homework $homework) => $homework->isDraft())
                ->confirm(__('Students and their parents are told about it straight away.'), __('Publish this homework?'))
                ->handle(fn (Homework $homework) => app(Homeworks::class)->publish($homework))
                ->success(__('Homework published.')),

            Action::make('edit', __('Edit'))
                ->icon('ri-pencil-line')
                ->can($looksAfter('homework.edit'))
                ->modal('forms.homework', fn (Homework $homework) => ['homework' => $homework->id], 'lg'),

            Action::make('close', __('Close submissions'))
                ->icon('ri-lock-line')
                ->color('secondary')
                ->can($looksAfter('homework.edit'))
                ->visible(fn (Homework $homework) => $homework->isPublished())
                ->handle(fn (Homework $homework) => app(Homeworks::class)->close($homework))
                ->success(__('Submissions closed.')),

            Action::make('reopen', __('Reopen submissions'))
                ->icon('ri-lock-unlock-line')
                ->color('secondary')
                ->can($looksAfter('homework.edit'))
                ->visible(fn (Homework $homework) => $homework->isClosed())
                ->handle(fn (Homework $homework) => app(Homeworks::class)->reopen($homework))
                ->success(__('Submissions reopened.')),

            Action::make('delete', __('Delete'))
                ->icon('ri-delete-bin-line')
                ->color('danger')
                ->can($looksAfter('homework.delete'))
                ->confirm(__('Students will no longer see it. Work already handed in is kept on record.'), __('Delete this homework?'))
                ->handle(fn (Homework $homework) => app(Homeworks::class)->delete($homework))
                ->success(__('Homework deleted.')),
        ];
    }

    public function searchPlaceholder(): string
    {
        return __('Search homework…');
    }

    public function emptyTitle(): string
    {
        return __('No homework yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Set homework for a class you teach; students see it once you publish it.');
    }
}
