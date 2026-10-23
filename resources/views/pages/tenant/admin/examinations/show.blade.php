<?php

/*
 * One examination: overview with marks-entry progress, the date sheet
 * editor and the result sheet.
 */

use App\Enums\EnrollmentStatus;
use App\Enums\ExamStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\ExamSchedule;
use App\Models\Examination;
use App\Models\Mark;
use App\Models\StudentEnrollment;
use App\Services\Exams\Grading;
use App\Services\Exams\ResultPublisher;
use App\Tables\ActionFailed;

use function Livewire\Volt\{computed, layout, mount, on, state, title, uses};

layout('layouts.app');
title(fn () => $this->exam->name);

uses([InteractsWithUi::class]);

state(['examination' => null])->locked();
state(['tab' => 'overview'])->url();

mount(function (int $examination) {
    $this->authorize('examinations.view');
    $this->examination = Examination::query()->findOrFail($examination)->id;

    if (! in_array($this->tab, ['overview', 'schedule', 'results'], true)) {
        $this->tab = 'overview';
    }
});

on(['refresh-tables' => function () {
    unset($this->exam);
}, 'date-sheet-saved' => function () {
    unset($this->progress);
}]);

$exam = computed(fn () => Examination::query()->with(['academicYear', 'term', 'gradeScale'])->findOrFail($this->examination));

// Per class: papers on the date sheet and how many marks are in.
$progress = computed(function () {
    $schedules = ExamSchedule::query()->with('schoolClass')->where('examination_id', $this->examination)->get()->groupBy('class_id');

    $students = StudentEnrollment::query()
        ->where('academic_year_id', $this->exam->academic_year_id)
        ->whereIn('class_id', $schedules->keys())
        ->whereNotIn('status', [EnrollmentStatus::Left, EnrollmentStatus::Transferred])
        ->selectRaw('class_id, count(*) as total')->groupBy('class_id')->toBase()->pluck('total', 'class_id');

    $marks = Mark::query()->whereIn('exam_schedule_id', $schedules->flatten()->pluck('id'))
        ->selectRaw('exam_schedule_id, count(*) as total')->groupBy('exam_schedule_id')->toBase()->pluck('total', 'exam_schedule_id');

    return $schedules->map(function ($papers, $classId) use ($students, $marks) {
        $expected = $papers->count() * (int) ($students[$classId] ?? 0);
        $entered = $papers->sum(fn ($paper) => (int) ($marks[$paper->id] ?? 0));

        return [
            'class' => $papers->first()->schoolClass,
            'papers' => $papers->count(),
            'students' => (int) ($students[$classId] ?? 0),
            'entered' => $entered,
            'expected' => $expected,
            'percent' => $expected ? min(100, (int) round($entered / $expected * 100)) : 0,
            'first' => $papers->min('exam_date'),
            'last' => $papers->max('exam_date'),
        ];
    })->sortBy(fn ($row) => [$row['class']?->numeric_level ?? 999, $row['class']?->name])->values();
});

$publish = function (ResultPublisher $publisher) {
    $this->authorize('examinations.publish');

    try {
        $publisher->publish($this->exam, auth()->user());
    } catch (ActionFailed $e) {
        $this->toast($e->getMessage(), 'error');

        return;
    }

    unset($this->exam);
    $this->toast(__('Results published. Students and parents have been told.'));
};

$unpublish = function (ResultPublisher $publisher) {
    $this->authorize('examinations.publish');
    $publisher->unpublish($this->exam, auth()->user());

    unset($this->exam);
    $this->toast(__('Results unpublished. Marks can be corrected again.'));
};

?>

@php
    $exam = $this->exam;
    $format = tenant()->setting('date_format');
    $scale = app(Grading::class)->scaleFor($exam);
@endphp

<div>
    <x-page-header :title="$exam->name"
                   :breadcrumbs="[__('School Admin') => route('admin.dashboard'), __('Examinations') => route('admin.examinations.index'), $exam->name => null]">
        <x-slot:actions>
            @can('examinations.edit')
                <button type="button" class="btn btn-light" wire:click="$dispatch('open-modal', { component: 'forms.examination', params: { examination: {{ $exam->id }} } })">
                    <i class="ri-pencil-line me-1"></i>{{ __('Edit') }}
                </button>
            @endcan
            @can('examinations.publish')
                @if ($exam->isPublished())
                    <button type="button" class="btn btn-warning"
                            x-on:click="SSMS.confirm(@js(['title' => __('Unpublish results?'), 'text' => __('Results are hidden from students and parents, and marks can be corrected.'), 'confirmText' => __('Unpublish'), 'cancelText' => __('Cancel')])).then(ok => ok && $wire.unpublish())">
                        <i class="ri-arrow-go-back-line me-1"></i>{{ __('Unpublish results') }}
                    </button>
                @elseif ($exam->status !== ExamStatus::Draft)
                    <button type="button" class="btn btn-success"
                            x-on:click="SSMS.confirm(@js(['title' => __('Publish results?'), 'text' => __('Students and parents can then see results and report cards, and marks are locked.'), 'confirmText' => __('Publish'), 'cancelText' => __('Cancel')])).then(ok => ok && $wire.publish())">
                        <i class="ri-send-plane-line me-1"></i>{{ __('Publish results') }}
                    </button>
                @endif
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap gap-4 align-items-center">
            <span class="badge bg-{{ $exam->status->color() }}-subtle text-{{ $exam->status->color() }} fs-12">{{ $exam->status->label() }}</span>
            <span class="text-muted"><i class="ri-calendar-line me-1"></i>{{ $exam->start_date->format($format) }} – {{ $exam->end_date->format($format) }}</span>
            <span class="text-muted"><i class="ri-calendar-2-line me-1"></i>{{ collect([$exam->academicYear?->name, $exam->term?->name])->filter()->implode(' · ') }}</span>
            <span class="text-muted"><i class="ri-award-line me-1"></i>{{ $scale?->name ?? __('No grade scale') }}</span>
            @if ($exam->results_published_at)
                <span class="text-success"><i class="ri-send-plane-line me-1"></i>{{ __('Published :date', ['date' => tenant()->localTime($exam->results_published_at)->format($format)]) }}</span>
            @endif
        </div>
        @if ($exam->status === ExamStatus::Draft)
            <div class="alert alert-light border-0 border-top rounded-0 mb-0 fs-13">
                <i class="ri-information-line me-1"></i>{{ __('Draft: set up the date sheet, then change the status to Scheduled so teachers can enter marks.') }}
            </div>
        @endif
    </div>

    <ul class="nav nav-tabs nav-tabs-bordered mb-4" role="tablist">
        @foreach (['overview' => __('Overview'), 'schedule' => __('Date sheet'), 'results' => __('Results')] as $key => $label)
            <li class="nav-item" role="presentation">
                <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        @class(['nav-link', 'active' => $tab === $key])>{{ $label }}</button>
            </li>
        @endforeach
    </ul>

    @if ($tab === 'overview')
        <div class="card mb-0">
            @if ($this->progress->isEmpty())
                <x-empty-state icon="ri-calendar-schedule-line" :title="__('No date sheet yet')" :message="__('Add each class\'s subjects, dates and marks on the Date sheet tab.')">
                    <button type="button" class="btn btn-primary mt-3" wire:click="$set('tab', 'schedule')">{{ __('Set up the date sheet') }}</button>
                </x-empty-state>
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light bg-opacity-30">
                            <tr>
                                <th scope="col" class="ps-4">{{ __('Class') }}</th>
                                <th scope="col">{{ __('Papers') }}</th>
                                <th scope="col">{{ __('Dates') }}</th>
                                <th scope="col">{{ __('Students') }}</th>
                                <th scope="col" class="pe-4" style="min-width: 14rem">{{ __('Marks entered') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->progress as $row)
                                <tr>
                                    <td class="ps-4 fw-medium">{{ $row['class']?->name }}</td>
                                    <td>{{ $row['papers'] }}</td>
                                    <td class="text-muted">{{ $row['first'] ? $row['first']->format($format).($row['last'] && ! $row['last']->eq($row['first']) ? ' – '.$row['last']->format($format) : '') : '—' }}</td>
                                    <td>{{ $row['students'] }}</td>
                                    <td class="pe-4">
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" style="height: 6px" role="progressbar" aria-valuenow="{{ $row['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                                                <div @class(['progress-bar', 'bg-success' => $row['percent'] === 100]) style="width: {{ $row['percent'] }}%"></div>
                                            </div>
                                            <small class="text-muted text-nowrap">{{ $row['entered'] }} / {{ $row['expected'] }}</small>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @elseif ($tab === 'schedule')
        <livewire:exams.date-sheet :examination="$exam->id" :key="'date-sheet-'.$exam->id" />
    @else
        <livewire:exams.results :examination="$exam->id" :key="'results-'.$exam->id" />
    @endif
</div>
