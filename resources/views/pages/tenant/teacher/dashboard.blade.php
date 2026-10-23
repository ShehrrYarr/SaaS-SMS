<?php

use App\Models\Section;
use App\Models\SubjectClass;

use function Livewire\Volt\{computed, layout, protect, title};

layout('layouts.app');
title(__('Dashboard'));

$staffId = protect(fn () => auth()->user()->staffProfile?->id);

$allocations = computed(fn () => $this->staffId()
    ? SubjectClass::query()->where('teacher_id', $this->staffId())->with(['subject', 'schoolClass', 'section'])->get()
    : collect());

$homeroomSections = computed(fn () => $this->staffId()
    ? Section::query()->where('class_teacher_id', $this->staffId())->with('schoolClass')->get()
    : collect());

?>

<div>
    <x-page-header :title="__('Dashboard')" :breadcrumbs="[__('Teacher') => null, __('Dashboard') => null]" />

    <div class="row g-4 mb-4">
        <div class="col-xl-6"><x-dashboard.welcome /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Subjects I teach')" :value="$this->allocations->count()" icon="ri-book-2-line" color="primary" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Class teacher of')" :value="$this->homeroomSections->count()" icon="ri-group-line" color="success" /></div>
    </div>

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
                                <td class="fw-medium">{{ $allocation->subject->name }}</td>
                                <td>{{ $allocation->schoolClass->name }}</td>
                                <td>{{ $allocation->section?->name ?? __('All sections') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="mt-4">
        <x-notices.latest />
    </div>
</div>
