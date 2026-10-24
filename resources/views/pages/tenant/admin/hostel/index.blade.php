<?php

/*
 * Hostel: buildings, rooms and beds, and the students staying in them.
 */

use App\Enums\RecordStatus;
use App\Models\HostelBuilding;
use App\Models\HostelRoom;
use App\Models\StudentHostel;
use App\Tables\HostelBuildingsTable;
use App\Tables\HostelRoomsTable;
use App\Tables\StudentHostelsTable;

use function Livewire\Volt\{computed, layout, mount, on, state, title};

layout('layouts.app');
title(__('Hostel'));

state(['tab' => 'rooms'])->url();

mount(function () {
    $this->authorize('hostel.view');

    if (! in_array($this->tab, ['rooms', 'buildings', 'residents'], true)) {
        $this->tab = 'rooms';
    }
});

on([
    'hostel-updated' => function () {
        unset($this->stats, $this->occupancy);
    },
    'table-action-done' => function () {
        unset($this->stats, $this->occupancy);
    },
]);

$stats = computed(function () {
    $beds = (int) HostelRoom::query()->where('status', RecordStatus::Active)->sum('capacity');
    $residents = StudentHostel::query()->active()->count();

    return [
        'buildings' => HostelBuilding::query()->count(),
        'rooms' => HostelRoom::query()->count(),
        'beds' => $beds,
        'residents' => $residents,
        'free' => max(0, $beds - $residents),
    ];
});

// Beds taken per building, for the occupancy bars.
$occupancy = computed(fn () => HostelBuilding::query()
    ->withSum(['rooms as beds' => fn ($query) => $query->where('status', RecordStatus::Active)], 'capacity')
    ->orderBy('name')
    ->get()
    ->map(fn (HostelBuilding $building) => [
        'building' => $building,
        'beds' => (int) $building->beds,
        'taken' => StudentHostel::query()->active()->whereHas('room', fn ($query) => $query->where('hostel_building_id', $building->id))->count(),
    ]));

?>

@php
    $stats = $this->stats;
    $tabs = [
        'rooms' => __('Rooms'),
        'buildings' => __('Buildings'),
        'residents' => __('Residents').($stats['residents'] ? ' ('.$stats['residents'].')' : ''),
    ];
    $create = match ($tab) {
        'buildings' => ['forms.hostel-building', __('Add a building')],
        'residents' => ['forms.student-hostel', __('Allocate a bed')],
        default => ['forms.hostel-room', __('Add a room')],
    };
@endphp

<div>
    <x-page-header :title="__('Hostel')" :breadcrumbs="[__('School Admin') => route('admin.dashboard'), __('Operations') => null, __('Hostel') => null]">
        @can('hostel.manage')
            <x-slot:actions>
                <button type="button" class="btn btn-primary" wire:click="$dispatch('open-modal', @js(['component' => $create[0]]))">
                    <i class="ri-add-line me-1"></i>{{ $create[1] }}
                </button>
            </x-slot:actions>
        @endcan
    </x-page-header>

    <div class="row g-4 mb-4">
        <div class="col-6 col-xl-3">
            <x-stat-card :label="__('Buildings')" :value="$stats['buildings']" icon="ri-building-line"
                         :hint="trans_choice(':count room|:count rooms', $stats['rooms'], ['count' => $stats['rooms']])" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card :label="__('Beds')" :value="$stats['beds']" icon="ri-hotel-bed-line" color="info" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card :label="__('Residents')" :value="$stats['residents']" icon="ri-group-line" color="success" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card :label="__('Beds free')" :value="$stats['free']" icon="ri-door-open-line" :color="$stats['free'] > 0 ? 'primary' : 'danger'" />
        </div>
    </div>

    @if ($this->occupancy->isNotEmpty())
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-4">
                    @foreach ($this->occupancy as $row)
                        @php($percent = $row['beds'] > 0 ? min(100, (int) round($row['taken'] / $row['beds'] * 100)) : 0)
                        <div class="col-md-6 col-xl-4" wire:key="occupancy-{{ $row['building']->id }}">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="fw-medium text-truncate">{{ $row['building']->name }} <small class="text-muted">{{ $row['building']->type->label() }}</small></span>
                                <small class="text-muted text-nowrap">{{ __(':taken of :beds beds', ['taken' => $row['taken'], 'beds' => $row['beds']]) }}</small>
                            </div>
                            <div class="progress" style="height: 6px" role="progressbar" aria-label="{{ $row['building']->name }}" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
                                <div @class(['progress-bar', 'bg-danger' => $percent >= 100, 'bg-warning' => $percent >= 80 && $percent < 100, 'bg-success' => $percent < 80]) style="width: {{ $percent }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <ul class="nav nav-tabs mb-4" role="tablist">
        @foreach ($tabs as $key => $label)
            <li class="nav-item" role="presentation">
                <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        @class(['nav-link', 'active' => $tab === $key])>{{ $label }}</button>
            </li>
        @endforeach
    </ul>

    @switch($tab)
        @case('buildings')
            <livewire:components.data-table :table="HostelBuildingsTable::class" wire:key="hostel-buildings" />
            @break
        @case('residents')
            <livewire:components.data-table :table="StudentHostelsTable::class" wire:key="hostel-residents" />
            @break
        @default
            <livewire:components.data-table :table="HostelRoomsTable::class" wire:key="hostel-rooms" />
    @endswitch
</div>
