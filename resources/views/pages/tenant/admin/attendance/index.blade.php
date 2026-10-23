<?php

/*
 * School Admin: every section's register for a day at a glance, and any
 * register opened for marking or correction.
 */

use App\Enums\AttendanceStatus;
use App\Services\Attendance\StudentAttendanceRegister;

use function Livewire\Volt\{computed, layout, mount, on, state, title};

layout('layouts.app');
title(__('Attendance'));

state(['date' => '', 'group' => null]);

mount(function () {
    $this->authorize('attendance.manage');
    $this->date = tenant()->today()->toDateString();
});

on(['attendance-saved' => function () {
    unset($this->summary);
}]);

$groups = computed(fn () => app(StudentAttendanceRegister::class)->groupsFor(auth()->user()));

$summary = computed(fn () => preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)
    ? app(StudentAttendanceRegister::class)->dailySummary(Illuminate\Support\Carbon::parse($this->date))
    : collect());

$sizes = computed(fn () => app(StudentAttendanceRegister::class)->rosterSizes());

$open = function (string $key) {
    $this->group = $this->groups->has($key) ? $key : null;
};

?>

@php
    $valid = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    $totals = ['students' => 0, 'taken' => 0, 'present' => 0, 'absent' => 0];
    foreach ($this->groups as $key => $item) {
        $counts = $this->summary->get($key, []);
        $totals['students'] += $this->sizes->get($key, 0);
        $totals['taken'] += array_sum($counts) > 0 ? 1 : 0;
        $totals['present'] += ($counts['present'] ?? 0) + ($counts['late'] ?? 0);
        $totals['absent'] += $counts['absent'] ?? 0;
    }
    $marked = $this->summary->flatten()->sum();
@endphp

<div>
    <x-page-header :title="__('Attendance')" :breadcrumbs="[__('School Admin') => route('admin.dashboard'), __('People') => null, __('Attendance') => null]">
        <x-slot:actions>
            @if (Route::has('admin.attendance.report'))
                <a href="{{ route('admin.attendance.report') }}" wire:navigate class="btn btn-light"><i class="ri-table-line me-1"></i>{{ __('Monthly report') }}</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap align-items-end gap-3">
            <div>
                <label class="form-label" for="attendance-date">{{ __('Date') }}</label>
                <input type="date" id="attendance-date" class="form-control" wire:model.live="date" max="{{ tenant()->today()->toDateString() }}">
            </div>
            @if ($date !== tenant()->today()->toDateString())
                <button type="button" class="btn btn-light" wire:click="$set('date', '{{ tenant()->today()->toDateString() }}')">{{ __('Today') }}</button>
            @endif
            @if ($group)
                <button type="button" class="btn btn-subtle-primary ms-auto" wire:click="$set('group', null)"><i class="ri-arrow-left-line me-1"></i>{{ __('All sections') }}</button>
            @endif
        </div>
    </div>

    @if ($group && $valid)
        <livewire:attendance.student-register :group-key="$group" :date="$date" :key="'admin-register-'.$group.'-'.$date" />
    @else
        <div class="row g-4 mb-4">
            <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Registers taken')" :value="$totals['taken'].' / '.$this->groups->count()" icon="ri-calendar-check-line" color="primary" /></div>
            <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Active students')" :value="number_format($totals['students'])" icon="ri-group-line" color="info" /></div>
            <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Present (incl. late)')" :value="number_format($totals['present'])" icon="ri-user-follow-line" color="success" /></div>
            <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Absent')" :value="number_format($totals['absent'])" icon="ri-user-unfollow-line" color="danger" /></div>
        </div>

        <div class="card mb-0">
            @if ($this->groups->isEmpty())
                <x-empty-state icon="ri-building-2-line" :title="__('No classes yet')" :message="__('Create classes and sections to take attendance.')" />
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light bg-opacity-30">
                            <tr>
                                <th scope="col" class="ps-4">{{ __('Section') }}</th>
                                <th scope="col">{{ __('Students') }}</th>
                                @foreach (AttendanceStatus::cases() as $status)
                                    <th scope="col" class="d-none d-md-table-cell">{{ $status->label() }}</th>
                                @endforeach
                                <th scope="col">{{ __('Register') }}</th>
                                <th scope="col" class="pe-4 text-end"><span class="visually-hidden">{{ __('Open') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->groups as $key => $item)
                                @php($counts = $this->summary->get($key, []))
                                @php($taken = array_sum($counts))
                                <tr wire:key="overview-{{ $key }}">
                                    <td class="ps-4 fw-medium">{{ $item['label'] }}</td>
                                    <td>{{ $this->sizes->get($key, 0) }}</td>
                                    @foreach (AttendanceStatus::cases() as $status)
                                        <td class="d-none d-md-table-cell">
                                            @if ($counts[$status->value] ?? 0)
                                                <span class="text-{{ $status->color() }} fw-medium">{{ $counts[$status->value] }}</span>
                                            @else
                                                <span class="text-muted">0</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td>
                                        @if ($taken === 0)
                                            <span class="badge bg-warning-subtle text-warning">{{ __('Not taken') }}</span>
                                        @elseif ($taken < $this->sizes->get($key, 0))
                                            <span class="badge bg-info-subtle text-info">{{ __('Partly taken') }}</span>
                                        @else
                                            <span class="badge bg-success-subtle text-success">{{ __('Taken') }}</span>
                                        @endif
                                    </td>
                                    <td class="pe-4 text-end">
                                        <button type="button" class="btn btn-sm btn-subtle-primary" wire:click="open('{{ $key }}')" @disabled(! $valid)>
                                            {{ $taken ? __('View / correct') : __('Take register') }}
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</div>
