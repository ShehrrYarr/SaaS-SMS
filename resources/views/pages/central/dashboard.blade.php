<?php

use App\Enums\SchoolStatus;
use App\Enums\StudentStatus;
use App\Enums\SubscriptionState;
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
    'students' => StudentProfile::query()->where('status', StudentStatus::Active)->count(),
    'staff' => StaffProfile::count(),
    'attention' => School::query()->where('status', SchoolStatus::Active)
        ->where(fn ($query) => $query->expiringWithin(14)->orWhere(fn ($query) => $query->whereSubscriptionState(SubscriptionState::Grace)))
        ->count(),
]);

$recentSchools = computed(fn () => School::query()->with('plan')->latest()->limit(5)->get());

?>

<div>
    <x-page-header :title="__('Platform overview')" :breadcrumbs="[__('Super Admin') => null, __('Dashboard') => null]">
        @can('manage-schools')
            <x-slot:actions>
                <a href="{{ route('central.schools.create') }}" wire:navigate class="btn btn-primary">
                    <i class="ri-add-line me-1"></i>{{ __('New school') }}
                </a>
            </x-slot:actions>
        @endcan
    </x-page-header>

    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Schools')" :value="number_format($this->stats['schools'])" icon="ri-building-4-line" color="primary"
            :hint="__(':active active · :suspended suspended', ['active' => $this->stats['active'], 'suspended' => $this->stats['suspended']])" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Active students')" :value="number_format($this->stats['students'])" icon="ri-graduation-cap-line" color="success" :hint="__('Across all schools')" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Staff')" :value="number_format($this->stats['staff'])" icon="ri-user-star-line" color="info" :hint="__('Across all schools')" /></div>
        <div class="col-sm-6 col-xl-3">
            <a href="{{ route('central.subscriptions.index') }}" wire:navigate class="text-reset text-decoration-none d-block h-100">
                <x-stat-card :label="__('Need a payment soon')" :value="number_format($this->stats['attention'])" icon="ri-alarm-warning-line" color="warning"
                             :hint="__('Ending within 14 days or in grace')" />
            </a>
        </div>
    </div>

    <div class="card mb-0">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h5 class="card-title mb-0">{{ __('Newest schools') }}</h5>
            <a href="{{ route('central.schools.index') }}" wire:navigate class="btn btn-sm btn-light">{{ __('All schools') }}</a>
        </div>
        @if ($this->recentSchools->isEmpty())
            <x-empty-state icon="ri-building-4-line" :title="__('No schools yet')" :message="__('Schools you provision will be listed here.')" />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle table-nowrap mb-0">
                    <thead class="bg-light bg-opacity-30">
                        <tr>
                            <th scope="col">{{ __('School') }}</th>
                            <th scope="col" class="d-none d-md-table-cell">{{ __('Plan') }}</th>
                            <th scope="col">{{ __('Subscription') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col" class="d-none d-md-table-cell">{{ __('Created') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->recentSchools as $school)
                            <tr wire:key="school-{{ $school->id }}">
                                <td>@include('tables.central.school-name', ['row' => $school])</td>
                                <td class="d-none d-md-table-cell">{{ $school->plan?->name ?? '—' }}</td>
                                <td>@include('tables.central.school-subscription', ['row' => $school])</td>
                                <td><span class="badge bg-{{ $school->status->color() }}-subtle text-{{ $school->status->color() }}">{{ $school->status->label() }}</span></td>
                                <td class="d-none d-md-table-cell text-muted">{{ $school->created_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
