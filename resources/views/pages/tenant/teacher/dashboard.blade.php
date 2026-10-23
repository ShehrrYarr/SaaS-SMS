<?php

/*
 * Teacher portal dashboard: today's classes, registers still to mark, work
 * waiting to be graded, and the subjects I teach.
 */

use App\Enums\SubmissionStatus;
use App\Models\HomeworkSubmission;
use App\Models\Section;
use App\Models\StudentAttendance;
use App\Models\SubjectClass;
use App\Services\Attendance\SchoolCalendar;
use App\Services\Attendance\StudentAttendanceRegister;
use App\Services\Homework\Homeworks;
use App\Services\Timetable\Timetables;

use function Livewire\Volt\{computed, layout, protect, title};

layout('layouts.app');
title(__('Dashboard'));

$staff = computed(fn () => auth()->user()->staffProfile()->first());

$staffId = protect(fn () => $this->staff?->id);

$allocations = computed(fn () => $this->staffId()
    ? SubjectClass::query()->where('teacher_id', $this->staffId())->with(['subject', 'schoolClass', 'section', 'academicYear'])->get()
        ->filter(fn (SubjectClass $allocation) => $allocation->academicYear?->isCurrent())
    : collect());

$homeroomSections = computed(fn () => $this->staffId()
    ? Section::query()->where('class_teacher_id', $this->staffId())->with('schoolClass')->get()
    : collect());

$today = computed(fn () => $this->staff && auth()->user()->can('timetable.view')
    ? Timetables::day(app(Timetables::class)->forTeacher($this->staff), tenant()->today()->isoWeekday())
    : collect());

// My homeroom registers for today, and whether each is marked.
$registers = computed(function () {
    $date = tenant()->today();

    if ($this->homeroomSections->isEmpty() || app(SchoolCalendar::class)->isWeeklyOff($date) || ! auth()->user()->can('attendance.mark')) {
        return collect();
    }

    $marked = StudentAttendance::query()->whereDate('date', $date)
        ->whereIn('section_id', $this->homeroomSections->modelKeys())
        ->distinct()->pluck('section_id');

    return $this->homeroomSections->map(fn (Section $section) => [
        'section' => $section,
        'key' => StudentAttendanceRegister::key($section->class_id, $section->id),
        'marked' => $marked->contains($section->id),
    ]);
});

$toGrade = computed(fn () => auth()->user()->can('homework.grade')
    ? HomeworkSubmission::query()
        ->whereIn('homework_id', app(Homeworks::class)->visibleTo(auth()->user())->select('id'))
        ->whereIn('status', [SubmissionStatus::Submitted, SubmissionStatus::Late])
        ->count()
    : null);

?>

<div>
    <x-page-header :title="__('Dashboard')" :breadcrumbs="[__('Teacher') => null, __('Dashboard') => null]" />

    <div class="row g-4 mb-4">
        <div class="col-xl-6"><x-dashboard.welcome /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Classes today')" :value="$this->today->count()" icon="ri-time-line" color="primary" /></div>
        <div class="col-sm-6 col-xl-3">
            @if ($this->toGrade !== null)
                <a href="{{ route('teacher.homework.index') }}" wire:navigate class="text-reset d-block h-100">
                    <x-stat-card :label="__('Work to grade')" :value="$this->toGrade" icon="ri-inbox-archive-line" :color="$this->toGrade > 0 ? 'warning' : 'success'" />
                </a>
            @else
                <x-stat-card :label="__('Class teacher of')" :value="$this->homeroomSections->count()" icon="ri-group-line" color="success" />
            @endif
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-5">
            <x-timetable.today :entries="$this->today" mode="teacher" :title="__('My classes today')" />
        </div>
        <div class="col-xl-7">
            @if ($this->registers->isNotEmpty())
                <div class="card mb-4">
                    <div class="card-header"><h5 class="card-title mb-0">{{ __('Today\'s registers') }}</h5></div>
                    <ul class="list-group list-group-flush">
                        @foreach ($this->registers as $register)
                            <li class="list-group-item hstack gap-3" wire:key="register-{{ $register['key'] }}">
                                <i @class(['fs-5', 'ri-checkbox-circle-fill text-success' => $register['marked'], 'ri-error-warning-line text-warning' => ! $register['marked']])></i>
                                <span class="flex-grow-1 fw-medium">{{ $register['section']->schoolClass?->name }} · {{ $register['section']->name }}</span>
                                @if ($register['marked'])
                                    <span class="text-muted fs-13">{{ __('Marked') }}</span>
                                @else
                                    <a href="{{ route('teacher.attendance.index', ['group' => $register['key']]) }}" wire:navigate class="btn btn-sm btn-primary">{{ __('Mark now') }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="card mb-0">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('My subjects') }}</h5></div>
                @if ($this->allocations->isEmpty())
                    <x-empty-state icon="ri-book-2-line" :title="__('No subjects assigned yet')" :message="__('Once the school admin allocates subjects to you, your classes will be listed here.')" />
                @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light bg-opacity-30"><tr><th>{{ __('Subject') }}</th><th>{{ __('Class') }}</th><th>{{ __('Section') }}</th></tr></thead>
                            <tbody>
                                @foreach ($this->allocations as $allocation)
                                    <tr wire:key="allocation-{{ $allocation->id }}">
                                        <td class="fw-medium">{{ $allocation->subject?->name }}</td>
                                        <td>{{ $allocation->schoolClass?->name }}</td>
                                        <td>{{ $allocation->section?->name ?? __('All sections') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <x-notices.latest />
</div>
