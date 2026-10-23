<?php

/*
 * Student and parent portals: homework set for the class — what's still to
 * do, what's been handed in, and what's been graded.
 */

use App\Enums\HomeworkStatus;
use App\Enums\SubmissionStatus;
use App\Services\Homework\Homeworks;
use App\Services\Portals\StudentOverview;
use App\Support\Portal;
use App\Support\SelfService;
use Illuminate\Database\Eloquent\Builder;

use function Livewire\Volt\{computed, layout, state, title, updated, usesPagination, with};

layout('layouts.app');
title(__('Homework'));

usesPagination(theme: 'bootstrap');

state(['tab' => 'to-do'])->url();

$student = computed(fn () => SelfService::student());

$toDoCount = computed(fn () => $this->student ? app(StudentOverview::class)->homeworkToDoCount($this->student) : 0);

updated(['tab' => fn () => $this->resetPage()]);

with(function () {
    if (! $this->student) {
        return ['items' => null];
    }

    $id = $this->student->id;
    $query = app(StudentOverview::class)->homework($this->student);

    match ($this->tab) {
        'to-do' => $query->where('status', HomeworkStatus::Published)
            ->whereDoesntHave('submissions', fn (Builder $query) => $query->where('student_profile_id', $id)->where('status', '!=', SubmissionStatus::Returned))
            ->orderBy('due_at'),
        'handed-in' => $query->whereHas('submissions', fn (Builder $query) => $query->where('student_profile_id', $id)->whereIn('status', [SubmissionStatus::Submitted, SubmissionStatus::Late]))
            ->orderByDesc('due_at'),
        'graded' => $query->whereHas('submissions', fn (Builder $query) => $query->where('student_profile_id', $id)->where('status', SubmissionStatus::Graded))
            ->orderByDesc('due_at'),
        default => $query->orderByDesc('due_at'),
    };

    return ['items' => $query->paginate(10)];
});

?>

@php
    $portal = Portal::current();
@endphp

<div>
    <x-page-header :title="__('Homework')" :breadcrumbs="SelfService::breadcrumbs([__('Homework') => null])" />

    @if (! $this->student)
        <x-family.missing />
    @else
        <ul class="nav nav-tabs nav-tabs-bordered mb-4" role="tablist">
            @foreach (['to-do' => __('To do'), 'handed-in' => __('Handed in'), 'graded' => __('Graded'), 'all' => __('All')] as $key => $label)
                <li class="nav-item" role="presentation">
                    <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                            @class(['nav-link', 'active' => $tab === $key])>
                        {{ $label }}
                        @if ($key === 'to-do' && $this->toDoCount > 0)<span class="badge bg-danger rounded-pill ms-1">{{ $this->toDoCount }}</span>@endif
                    </button>
                </li>
            @endforeach
        </ul>

        @if ($items->isEmpty())
            <div class="card mb-0">
                <x-empty-state icon="ri-book-open-line"
                               :title="$tab === 'to-do' ? __('All caught up') : __('Nothing here')"
                               :message="$tab === 'to-do' ? __('There is no homework waiting to be handed in.') : null" />
            </div>
        @else
            <div class="card mb-0">
                <ul class="list-group list-group-flush">
                    @foreach ($items as $homework)
                        @php
                            $submission = $homework->submissions->first();
                            $overdue = $homework->isPublished() && $homework->isPastDue() && (! $submission || $submission->status === SubmissionStatus::Returned);
                        @endphp
                        <li class="list-group-item py-3" wire:key="homework-{{ $homework->id }}">
                            <div class="d-flex flex-wrap align-items-start gap-3">
                                <span class="avatar-item avatar avatar-title bg-primary-subtle text-primary flex-shrink-0"><i class="ri-book-open-line"></i></span>
                                <div class="min-w-0 flex-grow-1">
                                    <a href="{{ route($portal.'.homework.show', $homework->id) }}" wire:navigate class="fw-semibold text-body d-block">{{ $homework->title }}</a>
                                    <small class="text-muted">
                                        {{ $homework->subjectClass?->subject?->name }}
                                        @if ($homework->subjectClass?->teacher?->user) · {{ $homework->subjectClass->teacher->user->name }} @endif
                                    </small>
                                </div>
                                <div class="text-end">
                                    @if ($submission)
                                        <span class="badge bg-{{ $submission->status->color() }}-subtle text-{{ $submission->status->color() }}">{{ $submission->status === SubmissionStatus::Returned ? __('Sent back') : $submission->status->label() }}</span>
                                        @if ($submission->isGraded() && $submission->marks !== null)
                                            <div class="fw-semibold mt-1">{{ Homeworks::number((float) $submission->marks) }}@if ($homework->max_marks !== null)<span class="text-muted fw-normal"> / {{ Homeworks::number((float) $homework->max_marks) }}</span>@endif</div>
                                        @endif
                                    @elseif ($homework->isClosed())
                                        <span class="badge bg-dark-subtle text-dark">{{ __('Closed') }}</span>
                                    @else
                                        <span @class(['badge', 'bg-danger-subtle text-danger' => $overdue, 'bg-warning-subtle text-warning' => ! $overdue])>{{ $overdue ? __('Overdue') : __('To do') }}</span>
                                    @endif
                                    <small @class(['d-block mt-1', 'text-danger' => $overdue, 'text-muted' => ! $overdue])>{{ __('Due :date', ['date' => $homework->dueLabel()]) }}</small>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($items->hasPages())
                    <div class="card-footer">{{ $items->links() }}</div>
                @endif
            </div>
        @endif
    @endif
</div>
