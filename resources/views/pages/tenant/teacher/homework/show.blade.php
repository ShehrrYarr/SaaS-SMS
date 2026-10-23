<?php

/*
 * Teacher portal: one homework — the brief, and every student's work with
 * its status, ready to review and grade.
 */

use App\Enums\SubmissionStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\StudentProfile;
use App\Services\Homework\Homeworks;
use App\Tables\ActionFailed;

use function Livewire\Volt\{computed, layout, mount, on, state, title, uses};

layout('layouts.app');
title(__('Homework'));

uses([InteractsWithUi::class]);

state(['homework' => null])->locked();

state(['filter' => 'all'])->url();

mount(function (int $homework) {
    $record = Homework::query()->with('subjectClass')->findOrFail($homework);
    abort_unless(app(Homeworks::class)->looksAfter(auth()->user(), $record), 404);

    $this->homework = $record->id;
});

$record = computed(fn () => Homework::query()
    ->with(['subjectClass.subject', 'subjectClass.schoolClass', 'subjectClass.section', 'section', 'creator'])
    ->findOrFail($this->homework));

$summary = computed(fn () => app(Homeworks::class)->summary($this->record));

// Every student it was set for, plus anyone who handed in before moving class.
$rows = computed(function () {
    $submissions = HomeworkSubmission::query()->where('homework_id', $this->homework)->get()->keyBy('student_profile_id');
    $roster = app(Homeworks::class)->roster($this->record);
    $moved = StudentProfile::query()->with('user')->whereIn('id', $submissions->keys()->diff($roster->modelKeys()))->get();

    return $roster->concat($moved)->map(fn (StudentProfile $student) => [
        'student' => $student,
        'submission' => $submissions->get($student->id),
        'moved' => $moved->contains('id', $student->id),
    ]);
});

$visibleRows = computed(fn () => $this->rows->filter(fn (array $row) => match ($this->filter) {
    'to-grade' => $row['submission']?->isHandedIn(),
    'missing' => ! $row['submission'] || $row['submission']->status === SubmissionStatus::Returned,
    'graded' => $row['submission']?->isGraded(),
    default => true,
}));

on(['homework-updated' => function () {
    unset($this->record, $this->summary, $this->rows, $this->visibleRows);
}]);

$run = function (string $action, Homeworks $homeworks) {
    $this->authorize('homework.edit');
    abort_unless($homeworks->looksAfter(auth()->user(), $this->record), 403);

    try {
        match ($action) {
            'publish' => $homeworks->publish($this->record),
            'close' => $homeworks->close($this->record),
            'reopen' => $homeworks->reopen($this->record),
            default => abort(404),
        };
    } catch (ActionFailed $e) {
        $this->toast($e->getMessage(), 'error');

        return;
    }

    unset($this->record);
    $this->toast(match ($action) {
        'publish' => __('Homework published.'),
        'close' => __('Submissions closed.'),
        default => __('Submissions reopened.'),
    });
};

?>

@php
    $homework = $this->record;
    $canEdit = auth()->user()->can('homework.edit');
    $canGrade = auth()->user()->can('homework.grade') && ! $homework->isDraft();
    $stats = $this->summary;
@endphp

<div>
    <x-page-header :title="$homework->title" :breadcrumbs="[__('Teacher') => route('teacher.dashboard'), __('Homework') => route('teacher.homework.index'), $homework->title => null]">
        @if ($canEdit)
            <x-slot:actions>
                <button type="button" class="btn btn-light" wire:click="$dispatch('open-modal', @js(['component' => 'forms.homework', 'params' => ['homework' => $homework->id], 'size' => 'lg']))">
                    <i class="ri-pencil-line me-1"></i>{{ __('Edit') }}
                </button>
                @if ($homework->isDraft())
                    <button type="button" class="btn btn-success" wire:click="run('publish')" wire:confirm="{{ __('Students and their parents are told about it straight away.') }}">
                        <i class="ri-send-plane-line me-1"></i>{{ __('Publish') }}
                    </button>
                @elseif ($homework->isPublished())
                    <button type="button" class="btn btn-light" wire:click="run('close')"><i class="ri-lock-line me-1"></i>{{ __('Close submissions') }}</button>
                @else
                    <button type="button" class="btn btn-light" wire:click="run('reopen')"><i class="ri-lock-unlock-line me-1"></i>{{ __('Reopen submissions') }}</button>
                @endif
            </x-slot:actions>
        @endif
    </x-page-header>

    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="card mb-0 h-100">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <span class="badge bg-{{ $homework->status->color() }}-subtle text-{{ $homework->status->color() }}">{{ $homework->status->label() }}</span>
                        <span class="text-muted fs-13"><i class="ri-group-line me-1"></i>{{ $homework->groupLabel() }}</span>
                    </div>
                    <dl class="row fs-13 mb-3">
                        <dt class="col-sm-3 text-muted fw-normal">{{ __('Due') }}</dt>
                        <dd @class(['col-sm-9', 'text-danger' => $homework->isPublished() && $homework->isPastDue()])>{{ $homework->dueLabel() }}</dd>
                        <dt class="col-sm-3 text-muted fw-normal">{{ __('Set on') }}</dt>
                        <dd class="col-sm-9">{{ $homework->assigned_on->format(tenant()->setting('date_format')) }}@if ($homework->creator) · {{ $homework->creator->name }}@endif</dd>
                        <dt class="col-sm-3 text-muted fw-normal">{{ __('Marks') }}</dt>
                        <dd class="col-sm-9">{{ $homework->max_marks !== null ? __('Out of :max', ['max' => Homeworks::number((float) $homework->max_marks)]) : __('Not marked out of a number') }}</dd>
                    </dl>
                    @if ($homework->description)
                        <div class="notice-body text-pre-line border-top pt-3">{{ $homework->description }}</div>
                    @endif
                    @if ($homework->attachment)
                        <a href="{{ route('tenant.homework.attachment', $homework->id) }}" class="btn btn-sm btn-light mt-3">
                            <i class="ri-attachment-2 me-1"></i>{{ $homework->attachmentName() }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="row g-3">
                <div class="col-6 col-xl-12"><x-stat-card :label="__('To grade')" :value="$stats['handed_in']" icon="ri-inbox-archive-line" color="info"
                                                          :hint="$stats['late'] ? __(':count late', ['count' => $stats['late']]) : null" /></div>
                <div class="col-6 col-xl-12"><x-stat-card :label="__('Graded')" :value="$stats['graded'].' / '.$stats['expected']" icon="ri-checkbox-circle-line" color="success" /></div>
            </div>
        </div>
    </div>

    @if ($homework->isDraft())
        <div class="alert alert-info fs-13">
            <i class="ri-information-line me-1"></i>{{ __('This homework is a draft: students can\'t see it until you publish it.') }}
        </div>
    @endif

    <div class="card mb-0">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <h5 class="card-title mb-0 flex-grow-1">{{ __('Students') }}</h5>
            <div class="btn-group btn-group-sm" role="group" aria-label="{{ __('Show') }}">
                @foreach (['all' => __('All'), 'to-grade' => __('To grade'), 'missing' => __('Not handed in'), 'graded' => __('Graded')] as $key => $label)
                    <button type="button" @class(['btn', 'btn-primary' => $filter === $key, 'btn-light' => $filter !== $key]) wire:click="$set('filter', '{{ $key }}')">{{ $label }}</button>
                @endforeach
            </div>
        </div>
        @if ($this->visibleRows->isEmpty())
            <x-empty-state icon="ri-group-line" :title="$this->rows->isEmpty() ? __('No students in this class') : __('Nobody here')"
                           :message="$this->rows->isEmpty() ? __('Students appear once they are placed in the class.') : null" class="py-6" />
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="bg-light bg-opacity-30">
                        <tr>
                            <th class="ps-4">{{ __('Roll') }}</th>
                            <th>{{ __('Student') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('Handed in') }}</th>
                            <th class="text-end">{{ __('Marks') }}</th>
                            <th class="pe-4 text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->visibleRows as $row)
                            @php($submission = $row['submission'])
                            <tr wire:key="student-{{ $row['student']->id }}">
                                <td class="ps-4 text-muted">{{ $row['student']->roll_number ?? '—' }}</td>
                                <td>
                                    <span class="fw-medium">{{ $row['student']->user?->name }}</span>
                                    <small class="d-block text-muted">{{ $row['student']->admission_number }}@if ($row['moved']) · {{ __('moved class') }}@endif</small>
                                </td>
                                <td>
                                    @if ($submission)
                                        <span class="badge bg-{{ $submission->status->color() }}-subtle text-{{ $submission->status->color() }}">{{ $submission->status === SubmissionStatus::Returned ? __('Sent back') : $submission->status->label() }}</span>
                                    @elseif (! $homework->isDraft() && $homework->isPastDue())
                                        <span class="badge bg-danger-subtle text-danger">{{ __('Missing') }}</span>
                                    @else
                                        <span class="badge bg-light text-muted border">{{ __('Not handed in') }}</span>
                                    @endif
                                </td>
                                <td class="d-none d-md-table-cell text-muted fs-13">
                                    {{ $submission?->submitted_at ? tenant()->localTime($submission->submitted_at)->format(tenant()->setting('date_format').' '.tenant()->setting('time_format')) : '—' }}
                                </td>
                                <td class="text-end">
                                    {{ $submission?->marks !== null ? Homeworks::number((float) $submission->marks).($homework->max_marks !== null ? ' / '.Homeworks::number((float) $homework->max_marks) : '') : '—' }}
                                </td>
                                <td class="pe-4 text-end">
                                    @if ($canGrade)
                                        <button type="button" @class(['btn btn-sm', 'btn-primary' => $submission?->isHandedIn(), 'btn-light' => ! $submission?->isHandedIn()])
                                                wire:click="$dispatch('open-modal', @js(['component' => 'forms.homework-review', 'params' => ['homework' => $homework->id, 'student' => $row['student']->id], 'size' => 'lg']))">
                                            {{ $submission?->isHandedIn() ? __('Review') : ($submission?->isGraded() ? __('Regrade') : __('Grade')) }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
