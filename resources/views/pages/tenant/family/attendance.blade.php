<?php

/*
 * Student and parent portals: attendance — this year's breakdown and a month
 * calendar of days marked.
 */

use App\Enums\AttendanceStatus;
use App\Services\Attendance\SchoolCalendar;
use App\Services\Portals\StudentOverview;
use App\Services\Timetable\BellSchedule;
use App\Support\SelfService;
use Illuminate\Support\Carbon;

use function Livewire\Volt\{computed, layout, mount, state, title};

layout('layouts.app');
title(__('Attendance'));

state(['month' => ''])->url();

$student = computed(fn () => SelfService::student());

$year = computed(fn () => app(StudentOverview::class)->year());

$yearStats = computed(fn () => $this->student ? app(StudentOverview::class)->attendance($this->student) : null);

$monthStart = computed(function () {
    try {
        return Carbon::createFromFormat('!Y-m', $this->month)->startOfMonth();
    } catch (Throwable) {
        return tenant()->today()->startOfMonth();
    }
});

$records = computed(fn () => $this->student ? app(StudentOverview::class)->attendanceMonth($this->student, $this->monthStart) : collect());

$monthStats = computed(fn () => $this->student
    ? app(StudentOverview::class)->attendance($this->student, $this->monthStart, $this->monthStart->copy()->endOfMonth())
    : null);

mount(function () {
    if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
        $this->month = tenant()->today()->format('Y-m');
    }
});

$shift = function (int $months) {
    $this->month = $this->monthStart->copy()->addMonths($months)->format('Y-m');
    unset($this->monthStart, $this->records, $this->monthStats);
};

?>

@php
    $today = tenant()->today();
    $calendar = app(SchoolCalendar::class);
    $weekStart = (int) tenant()->setting('week_starts_on');
@endphp

<div>
    <x-page-header :title="__('Attendance')" :breadcrumbs="SelfService::breadcrumbs([__('Attendance') => null])" />

    @if (! $this->student)
        <x-family.missing />
    @else
        @php($stats = $this->yearStats)
        <div class="row g-4 mb-4">
            <div class="col-xl-4">
                <div class="card mb-0 h-100">
                    <div class="card-header hstack gap-2">
                        <h5 class="card-title mb-0 flex-grow-1">{{ __('This year') }}</h5>
                        @if ($this->year)<span class="badge bg-light text-muted border">{{ $this->year->name }}</span>@endif
                    </div>
                    <div class="card-body">
                        @if ($stats['marked'] === 0)
                            <x-empty-state icon="ri-calendar-check-line" :title="__('No attendance marked yet')" class="py-4" />
                        @else
                            <div class="text-center mb-2">
                                <span class="display-6 fw-semibold">{{ $stats['rate'] !== null ? $stats['rate'].'%' : '—' }}</span>
                                <p class="mb-0"><x-family.attendance-line :present="$stats['present']" :school-days="$stats['school_days']" :excused="$stats['excused']" class="text-muted fs-13" /></p>
                            </div>
                            <x-chart height="220" :options="[
                                'chart' => ['type' => 'donut'],
                                'series' => collect(AttendanceStatus::cases())->map(fn ($status) => $stats['counts'][$status->value] ?? 0)->all(),
                                'labels' => collect(AttendanceStatus::cases())->map->label()->all(),
                                'colors' => collect(AttendanceStatus::cases())->map(fn ($status) => '--bs-'.$status->color())->all(),
                                'plotOptions' => ['pie' => ['donut' => ['size' => '62%']]],
                            ]" />
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-xl-8">
                <div class="row g-3 h-100">
                    @foreach (AttendanceStatus::cases() as $status)
                        <div class="col-6">
                            <div class="card mb-0 h-100">
                                <div class="card-body">
                                    <span class="text-muted fs-13">{{ $status->label() }}</span>
                                    <div class="fs-3 fw-semibold text-{{ $status->color() }}">{{ $stats['counts'][$status->value] ?? 0 }}</div>
                                    <small class="text-muted">{{ trans_choice(':count day this month|:count days this month', $this->monthStats['counts'][$status->value] ?? 0, ['count' => $this->monthStats['counts'][$status->value] ?? 0]) }}</small>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card mb-0">
            <div class="card-header hstack gap-2">
                <button type="button" class="btn btn-sm btn-light icon-btn-sm" wire:click="shift(-1)" aria-label="{{ __('Previous month') }}"><i class="ri-arrow-left-s-line"></i></button>
                <h5 class="card-title mb-0 text-center flex-grow-1">{{ $this->monthStart->translatedFormat('F Y') }}</h5>
                <button type="button" class="btn btn-sm btn-light icon-btn-sm" wire:click="shift(1)" aria-label="{{ __('Next month') }}" @disabled($this->monthStart->gte($today->copy()->startOfMonth()))><i class="ri-arrow-right-s-line"></i></button>
            </div>
            <div class="card-body">
                <div class="attendance-calendar" role="grid" aria-label="{{ $this->monthStart->translatedFormat('F Y') }}">
                    @for ($i = 0; $i < 7; $i++)
                        <div class="weekday" role="columnheader">{{ BellSchedule::dayName(($weekStart - 1 + $i) % 7 + 1, short: true) }}</div>
                    @endfor

                    @for ($blank = ($this->monthStart->isoWeekday() - $weekStart + 7) % 7; $blank > 0; $blank--)
                        <div aria-hidden="true"></div>
                    @endfor

                    @foreach (Carbon::parse($this->monthStart)->daysUntil($this->monthStart->copy()->endOfMonth()) as $day)
                        @php($record = $this->records->get($day->toDateString()))
                        <div role="gridcell" @class(['day', 'is-off' => $calendar->isWeeklyOff($day), 'is-today' => $day->isSameDay($today)])
                             @if ($record?->remarks) title="{{ $record->remarks }}" @endif>
                            <span class="fw-medium">{{ $day->day }}</span>
                            @if ($record)
                                <span class="badge bg-{{ $record->status->color() }}-subtle text-{{ $record->status->color() }} d-block mt-1 text-truncate">{{ $record->status->label() }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>

                @php($notes = $this->records->filter(fn ($record) => filled($record->remarks)))
                @if ($notes->isNotEmpty())
                    <h6 class="mt-4 mb-2 fs-13 text-muted">{{ __('Notes') }}</h6>
                    <ul class="list-unstyled mb-0 fs-13">
                        @foreach ($notes as $record)
                            <li class="mb-1"><span class="fw-medium">{{ $record->date->format(tenant()->setting('date_format')) }}</span> — {{ $record->remarks }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif
</div>
