<?php

/*
 * Teacher portal: my week — every period of the subjects I teach.
 */

use App\Services\Timetable\BellSchedule;
use App\Services\Timetable\Timetables;

use function Livewire\Volt\{computed, layout, title};

layout('layouts.app');
title(__('My timetable'));

$staff = computed(fn () => auth()->user()->staffProfile()->first());

$periods = computed(fn () => app(BellSchedule::class)->periods());

$days = computed(fn () => app(BellSchedule::class)->days());

$week = computed(fn () => $this->staff ? app(Timetables::class)->forTeacher($this->staff) : collect());

$today = computed(fn () => Timetables::day($this->week, tenant()->today()->isoWeekday()));

?>

<div>
    <x-page-header :title="__('My timetable')" :breadcrumbs="[__('Teacher') => route('teacher.dashboard'), __('My timetable') => null]" />

    @if ($this->periods->isEmpty() || $this->week->isEmpty())
        <div class="card mb-0">
            <x-empty-state icon="ri-time-line" :title="__('No timetable yet')"
                           :message="__('Your periods appear here once the school builds the timetable for the classes you teach.')" />
        </div>
    @else
        <x-timetable.today :entries="$this->today" mode="teacher" class="mb-4" />

        <div class="card mb-0">
            <div class="card-header"><h5 class="card-title mb-0">{{ __('This week') }}</h5></div>
            <x-timetable.week :periods="$this->periods" :days="$this->days" :entries="$this->week" mode="teacher" :today="tenant()->today()->isoWeekday()" />
        </div>
    @endif
</div>
