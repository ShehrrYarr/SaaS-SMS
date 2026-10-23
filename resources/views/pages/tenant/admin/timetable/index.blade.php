<?php

/*
 * Timetable builder: the school day (bell schedule), then each class or
 * section's week, cell by cell, from its subject allocations. A teacher's
 * week can be looked up too, to plan around clashes.
 */

use App\Models\SchoolClass;
use App\Models\StaffProfile;
use App\Models\SubjectClass;
use App\Services\Attendance\StudentAttendanceRegister;
use App\Services\Timetable\BellSchedule;
use App\Services\Timetable\Timetables;

use function Livewire\Volt\{computed, layout, mount, on, state, title};

layout('layouts.app');
title(__('Timetable'));

state(['by' => 'class', 'group' => '', 'teacher' => ''])->url();

$year = computed(fn () => app(Timetables::class)->year());

$periods = computed(fn () => app(BellSchedule::class)->periods());

$days = computed(fn () => app(BellSchedule::class)->days());

// "classId-sectionId" => "Grade 5 · A", every section of every class.
$groups = computed(function () {
    $groups = [];

    foreach (SchoolClass::query()->ordered()->with(['sections' => fn ($query) => $query->ordered()])->get() as $class) {
        if ($class->sections->isEmpty()) {
            $groups[StudentAttendanceRegister::key($class->id, null)] = ['class' => $class, 'section' => null, 'label' => $class->name];
        }

        foreach ($class->sections as $section) {
            $groups[StudentAttendanceRegister::key($class->id, $section->id)] = ['class' => $class, 'section' => $section, 'label' => $class->name.' · '.$section->name];
        }
    }

    return collect($groups);
});

$current = computed(fn () => $this->groups->get($this->group));

$teachers = computed(fn () => StaffProfile::query()
    ->whereIn('id', SubjectClass::query()->where('academic_year_id', $this->year?->id ?? 0)->whereNotNull('teacher_id')->select('teacher_id'))
    ->with('user')
    ->get()
    ->sortBy(fn (StaffProfile $staff) => mb_strtolower((string) $staff->user?->name))
    ->mapWithKeys(fn (StaffProfile $staff) => [$staff->id => $staff->user?->name]));

$week = computed(function () {
    if (! $this->year) {
        return collect();
    }

    if ($this->by === 'teacher') {
        $teacher = $this->teacher ? StaffProfile::query()->find($this->teacher) : null;

        return $teacher ? app(Timetables::class)->forTeacher($teacher, $this->year) : collect();
    }

    return $this->current ? app(Timetables::class)->grid($this->current['class'], $this->current['section'], $this->year) : collect();
});

// Periods a week each allocation should have, and how many it has so far.
$load = computed(function () {
    if ($this->by !== 'class' || ! $this->current || ! $this->year) {
        return collect();
    }

    $scheduled = $this->week->countBy('subject_class_id');

    return app(Timetables::class)->allocationsFor($this->current['class'], $this->current['section'], $this->year)
        ->map(fn (SubjectClass $allocation) => [
            'allocation' => $allocation,
            'scheduled' => $scheduled->get($allocation->id, 0),
            'target' => $allocation->periods_per_week,
        ]);
});

mount(function () {
    $this->by = in_array($this->by, ['class', 'teacher'], true) ? $this->by : 'class';

    if (! $this->groups->has($this->group)) {
        $this->group = (string) ($this->groups->keys()->first() ?? '');
    }

    if (! $this->teachers->has((int) $this->teacher)) {
        $this->teacher = (string) ($this->teachers->keys()->first() ?? '');
    }
});

on(['timetable-updated' => function () {
    unset($this->periods, $this->week, $this->load);
}]);

?>

@php($canManage = auth()->user()->can('timetable.manage'))

<div>
    <x-page-header :title="__('Timetable')" :breadcrumbs="[__('Admin') => route('admin.dashboard'), __('Timetable') => null]">
        @if ($canManage)
            <x-slot:actions>
                <button type="button" class="btn btn-light" wire:click="$dispatch('open-modal', { component: 'forms.bell-schedule', size: 'lg' })">
                    <i class="ri-alarm-line me-1"></i>{{ __('School day') }}
                </button>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if (! $this->year)
        <div class="card mb-0">
            <x-empty-state icon="ri-calendar-2-line" :title="__('No current academic year')"
                           :message="__('Timetables belong to an academic year. Mark one as current under Academic Setup first.')" />
        </div>
    @elseif ($this->periods->isEmpty())
        <div class="card mb-0">
            <x-empty-state icon="ri-alarm-line" :title="__('Set up the school day first')"
                           :message="__('Add the periods of a school day and their times. Every class\'s timetable is built on them.')">
                @if ($canManage)
                    <button type="button" class="btn btn-primary mt-4" wire:click="$dispatch('open-modal', { component: 'forms.bell-schedule', size: 'lg' })">
                        <i class="ri-alarm-line me-1"></i>{{ __('Set up the school day') }}
                    </button>
                @endif
            </x-empty-state>
        </div>
    @else
        <div class="card mb-4">
            <div class="card-body d-flex flex-wrap align-items-end gap-3">
                <div class="btn-group" role="group" aria-label="{{ __('View') }}">
                    <button type="button" @class(['btn', 'btn-primary' => $by === 'class', 'btn-light' => $by !== 'class']) wire:click="$set('by', 'class')">
                        <i class="ri-group-line me-1"></i>{{ __('By class') }}
                    </button>
                    <button type="button" @class(['btn', 'btn-primary' => $by === 'teacher', 'btn-light' => $by !== 'teacher']) wire:click="$set('by', 'teacher')">
                        <i class="ri-user-star-line me-1"></i>{{ __('By teacher') }}
                    </button>
                </div>

                @if ($by === 'class')
                    <div style="min-width: 14rem">
                        <label class="form-label" for="timetable-group">{{ __('Class') }}</label>
                        <select id="timetable-group" class="form-select" wire:model.live="group">
                            @foreach ($this->groups as $key => $option)
                                <option value="{{ $key }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <div style="min-width: 14rem">
                        <label class="form-label" for="timetable-teacher">{{ __('Teacher') }}</label>
                        <select id="timetable-teacher" class="form-select" wire:model.live="teacher">
                            @forelse ($this->teachers as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @empty
                                <option value="">{{ __('No teachers allocated this year') }}</option>
                            @endforelse
                        </select>
                    </div>
                @endif

                <span class="ms-auto badge bg-primary-subtle text-primary py-2 px-3">{{ $this->year->name }}</span>
            </div>
        </div>

        @if ($by === 'class' && ! $this->current)
            <div class="card mb-0">
                <x-empty-state icon="ri-group-line" :title="__('No classes yet')" :message="__('Add classes and sections under Academic Setup, then build their timetables here.')" />
            </div>
        @elseif ($by === 'teacher' && $this->teachers->isEmpty())
            <div class="card mb-0">
                <x-empty-state icon="ri-user-star-line" :title="__('No teachers allocated')" :message="__('Allocate subjects to teachers first; their weeks appear here.')" />
            </div>
        @else
            <div class="card mb-4">
                <div class="card-header hstack gap-2">
                    <h5 class="card-title mb-0 flex-grow-1">
                        {{ $by === 'class' ? $this->current['label'] : $this->teachers->get((int) $teacher) }}
                    </h5>
                    @if ($by === 'class' && $canManage)
                        <small class="text-muted d-none d-sm-inline">{{ __('Click a period to set or change it.') }}</small>
                    @endif
                </div>
                <x-timetable.week :periods="$this->periods" :days="$this->days" :entries="$this->week"
                                  :mode="$by === 'teacher' ? 'teacher' : 'class'"
                                  :edit="$by === 'class' && $canManage ? ['classId' => $this->current['class']->id, 'sectionId' => $this->current['section']?->id] : null" />
            </div>

            @if ($by === 'class')
                <div class="card mb-0">
                    <div class="card-header"><h5 class="card-title mb-0">{{ __('Subjects this week') }}</h5></div>
                    @if ($this->load->isEmpty())
                        <x-empty-state icon="ri-book-2-line" :title="__('No subjects allocated')"
                                       :message="__('Allocate subjects and teachers to this class under Subject Allocation; they can then be timetabled.')" />
                    @else
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="bg-light bg-opacity-30">
                                    <tr>
                                        <th class="ps-4">{{ __('Subject') }}</th>
                                        <th>{{ __('Teacher') }}</th>
                                        <th class="text-end pe-4">{{ __('Periods a week') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->load as $row)
                                        @php($short = $row['target'] && $row['scheduled'] < $row['target'])
                                        <tr wire:key="load-{{ $row['allocation']->id }}">
                                            <td class="ps-4 fw-medium">{{ $row['allocation']->subject?->name }}
                                                @if (! $row['allocation']->section_id && $this->current['section'])
                                                    <small class="text-muted ms-1">{{ __('all sections') }}</small>
                                                @endif
                                            </td>
                                            <td>{{ $row['allocation']->teacher?->user?->name ?? '—' }}</td>
                                            <td class="text-end pe-4">
                                                <span @class(['fw-semibold', 'text-warning' => $short, 'text-success' => $row['target'] && ! $short])>{{ $row['scheduled'] }}</span>
                                                @if ($row['target'])<span class="text-muted"> / {{ $row['target'] }}</span>@endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif
        @endif
    @endif
</div>
