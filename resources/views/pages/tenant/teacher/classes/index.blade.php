<?php

/*
 * Teacher portal: the sections I am class teacher of or teach a subject in,
 * with their students.
 */

use App\Models\AcademicYear;
use App\Models\Section;
use App\Models\SubjectClass;
use App\Services\Attendance\StudentAttendanceRegister;

use function Livewire\Volt\{computed, layout, state, title};

layout('layouts.app');
title(__('My classes'));

state(['open' => null]);

$staff = computed(fn () => auth()->user()->staffProfile()->first());

$cards = computed(function () {
    if (! $this->staff) {
        return collect();
    }

    $year = AcademicYear::query()->current()->value('id');
    $cards = collect();

    foreach (Section::query()->with('schoolClass')->where('class_teacher_id', $this->staff->id)->get() as $section) {
        $key = StudentAttendanceRegister::key($section->class_id, $section->id);
        $cards[$key] = ['key' => $key, 'class' => $section->schoolClass, 'section' => $section, 'class_teacher' => true, 'subjects' => collect()];
    }

    $allocations = SubjectClass::query()->with(['subject', 'schoolClass', 'section'])
        ->where('teacher_id', $this->staff->id)
        ->when($year, fn ($query) => $query->where('academic_year_id', $year))
        ->get();

    foreach ($allocations as $allocation) {
        $key = StudentAttendanceRegister::key($allocation->class_id, $allocation->section_id);
        $card = $cards[$key] ?? ['key' => $key, 'class' => $allocation->schoolClass, 'section' => $allocation->section, 'class_teacher' => false, 'subjects' => collect()];
        $card['subjects']->push($allocation->subject?->name);
        $cards[$key] = $card;
    }

    $sizes = app(StudentAttendanceRegister::class)->rosterSizes();

    return $cards->filter(fn ($card) => $card['class'])
        ->map(fn ($card) => [...$card, 'students' => $sizes->get($card['key'], 0)])
        ->sortBy(fn ($card) => [$card['class']->numeric_level ?? 999, $card['class']->name, $card['section']?->name])
        ->values();
});

$roster = computed(function () {
    $card = $this->cards->firstWhere('key', $this->open);

    return $card ? app(StudentAttendanceRegister::class)->roster($card['class'], $card['section'])->load('parents.user') : collect();
});

?>

<div>
    <x-page-header :title="__('My classes')" :breadcrumbs="[__('Teacher') => route('teacher.dashboard'), __('My classes') => null]" />

    @if ($this->cards->isEmpty())
        <div class="card mb-0">
            <x-empty-state icon="ri-group-line" :title="__('No classes assigned yet')"
                           :message="__('Sections appear here once the school makes you class teacher or allocates you a subject.')" />
        </div>
    @else
        <div class="row g-4 mb-4">
            @foreach ($this->cards as $card)
                <div class="col-md-6 col-xl-4" wire:key="card-{{ $card['key'] }}">
                    <div @class(['card mb-0 h-100', 'border-primary' => $open === $card['key']])>
                        <div class="card-body">
                            <div class="d-flex align-items-start gap-2 mb-2">
                                <h5 class="mb-0 flex-grow-1">{{ $card['class']->name }}{{ $card['section'] ? ' · '.$card['section']->name : '' }}</h5>
                                @if ($card['class_teacher'])
                                    <span class="badge bg-primary-subtle text-primary">{{ __('Class teacher') }}</span>
                                @endif
                            </div>
                            <p class="text-muted fs-13 mb-3">
                                {{ trans_choice(':count student|:count students', $card['students'], ['count' => $card['students']]) }}
                                @if ($card['subjects']->isNotEmpty()) · {{ $card['subjects']->filter()->implode(', ') }} @endif
                            </p>
                            <div class="d-flex flex-wrap gap-2">
                                @can('attendance.mark')
                                    <a href="{{ route('teacher.attendance.index', ['group' => $card['key']]) }}" wire:navigate class="btn btn-sm btn-primary">
                                        <i class="ri-calendar-check-line me-1"></i>{{ __('Attendance') }}
                                    </a>
                                @endcan
                                <button type="button" class="btn btn-sm btn-light" wire:click="$set('open', '{{ $open === $card['key'] ? '' : $card['key'] }}')">
                                    <i class="ri-group-line me-1"></i>{{ $open === $card['key'] ? __('Hide students') : __('Students') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($open && $this->roster->isNotEmpty())
            <div class="card mb-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light bg-opacity-30">
                            <tr>
                                <th scope="col" class="ps-4">{{ __('Roll') }}</th>
                                <th scope="col">{{ __('Student') }}</th>
                                <th scope="col">{{ __('Guardian') }}</th>
                                <th scope="col" class="pe-4 d-none d-md-table-cell">{{ __('Medical notes') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->roster as $student)
                                @php($guardian = $student->parents->firstWhere('pivot.is_primary', true) ?? $student->parents->first())
                                <tr>
                                    <td class="ps-4 text-muted">{{ $student->roll_number ?? '—' }}</td>
                                    <td><span class="fw-medium">{{ $student->user?->name }}</span><small class="d-block text-muted">{{ $student->admission_number }}</small></td>
                                    <td>{{ $guardian?->user?->name ?? '—' }}<small class="d-block text-muted">{{ $guardian?->user?->phone }}</small></td>
                                    <td class="pe-4 d-none d-md-table-cell text-muted fs-13">{{ Str::limit((string) $student->medical_notes, 80) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
</div>
