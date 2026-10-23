<?php

use function Livewire\Volt\{computed, layout, title};

layout('layouts.app');
title(__('Dashboard'));

$profile = computed(fn () => auth()->user()->studentProfile()->with(['schoolClass', 'section'])->first());

?>

<div>
    <x-page-header :title="__('Dashboard')" :breadcrumbs="[__('Student') => null, __('Dashboard') => null]" />

    <div class="row g-4">
        <div class="col-xl-6">
            <x-dashboard.welcome :subtitle="$this->profile
                ? trim(($this->profile->schoolClass?->name ?? '').' '.($this->profile->section?->name ?? '')).' · '.__('Admission no. :number', ['number' => $this->profile->admission_number])
                : null" />
        </div>
        <div class="col-xl-6">
            <div class="card mb-0 h-100">
                <x-empty-state icon="ri-calendar-check-line" :title="__('Nothing here yet')" :message="__('Your timetable, attendance, homework and results will appear here as your school records them.')" />
            </div>
        </div>
    </div>

    <div class="mt-4">
        <x-notices.latest />
    </div>
</div>
