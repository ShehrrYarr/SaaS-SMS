<?php

use App\Enums\SchoolStatus;
use App\Models\School;
use App\Models\StaffProfile;
use App\Models\StudentProfile;

use function Livewire\Volt\{computed, layout, title};

layout('layouts.super-admin');
title(__('Dashboard'));

// Runs in the central context: these counts span every school.
$stats = computed(fn () => [
    'schools' => School::count(),
    'active' => School::query()->where('status', SchoolStatus::Active)->count(),
    'suspended' => School::query()->where('status', SchoolStatus::Suspended)->count(),
    'students' => StudentProfile::count(),
    'staff' => StaffProfile::count(),
]);

$recentSchools = computed(fn () => School::query()->with('plan')->latest()->limit(5)->get());

?>

<div>
    <x-page-header :title="__('Platform overview')" :breadcrumbs="[__('Super Admin') => null, __('Dashboard') => null]" />

    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Schools')" :value="number_format($this->stats['schools'])" icon="ri-building-4-line" color="primary"
            :hint="__(':active active · :suspended suspended', ['active' => $this->stats['active'], 'suspended' => $this->stats['suspended']])" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Students')" :value="number_format($this->stats['students'])" icon="ri-graduation-cap-line" color="success" :hint="__('Across all schools')" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Staff')" :value="number_format($this->stats['staff'])" icon="ri-user-star-line" color="info" :hint="__('Across all schools')" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Suspended')" :value="number_format($this->stats['suspended'])" icon="ri-forbid-line" color="danger" /></div>
    </div>

    <div class="card mb-0">
        <div class="card-header"><h5 class="card-title mb-0">{{ __('Newest schools') }}</h5></div>
        @if ($this->recentSchools->isEmpty())
            <x-empty-state icon="ri-building-4-line" :title="__('No schools yet')" :message="__('Schools you provision will be listed here.')" />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light bg-opacity-30">
                        <tr><th>{{ __('School') }}</th><th>{{ __('Address') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Status') }}</th><th>{{ __('Created') }}</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($this->recentSchools as $school)
                            <tr wire:key="school-{{ $school->id }}">
                                <td><x-brand :name="$school->name" :logo="$school->logoUrl()" /></td>
                                <td><a href="{{ route('tenant.login', ['school' => $school->slug]) }}" target="_blank" rel="noopener">/{{ $school->slug }}</a></td>
                                <td>{{ $school->plan?->name ?? '—' }}</td>
                                <td><span class="badge bg-{{ $school->status->color() }}-subtle text-{{ $school->status->color() }}">{{ $school->status->label() }}</span></td>
                                <td class="text-muted">{{ $school->created_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
