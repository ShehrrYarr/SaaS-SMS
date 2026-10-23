<?php

/*
 * Student and parent portals: the class's weekly timetable.
 */

use App\Services\Timetable\BellSchedule;
use App\Services\Timetable\Timetables;
use App\Support\SelfService;

use function Livewire\Volt\{computed, layout, title};

layout('layouts.app');
title(__('Timetable'));

$student = computed(fn () => SelfService::student());

$periods = computed(fn () => app(BellSchedule::class)->periods());

$days = computed(fn () => app(BellSchedule::class)->days());

$week = computed(fn () => $this->student ? app(Timetables::class)->forStudent($this->student) : collect());

?>

<div>
    <x-page-header :title="__('Timetable')" :breadcrumbs="SelfService::breadcrumbs([__('Timetable') => null])" />

    @if (! $this->student)
        <x-family.missing />
    @elseif ($this->periods->isEmpty() || $this->week->isEmpty())
        <div class="card mb-0">
            <x-empty-state icon="ri-time-line" :title="__('No timetable yet')"
                           :message="__('The week\'s periods appear here once the school publishes the timetable for :class.', ['class' => $this->student->placementLabel() ?? __('the class')])" />
        </div>
    @else
        <x-timetable.today :entries="Timetables::day($this->week, tenant()->today()->isoWeekday())" class="mb-4" />

        <div class="card mb-0">
            <div class="card-header hstack gap-2">
                <h5 class="card-title mb-0 flex-grow-1">{{ __('This week') }}</h5>
                <span class="badge bg-light text-muted border">{{ $this->student->placementLabel() }}</span>
            </div>
            <x-timetable.week :periods="$this->periods" :days="$this->days" :entries="$this->week" :today="tenant()->today()->isoWeekday()" />
        </div>
    @endif
</div>
