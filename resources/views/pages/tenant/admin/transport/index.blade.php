<?php

/*
 * School transport: bus routes, vehicles and the students who ride them.
 */

use App\Enums\RecordStatus;
use App\Models\StudentTransport;
use App\Models\TransportRoute;
use App\Models\TransportVehicle;
use App\Tables\StudentTransportsTable;
use App\Tables\TransportRoutesTable;
use App\Tables\TransportVehiclesTable;

use function Livewire\Volt\{computed, layout, mount, on, state, title};

layout('layouts.app');
title(__('Transport'));

state(['tab' => 'routes'])->url();

mount(function () {
    $this->authorize('transport.view');

    if (! in_array($this->tab, ['routes', 'vehicles', 'students'], true)) {
        $this->tab = 'routes';
    }
});

on([
    'transport-updated' => function () {
        unset($this->stats);
    },
    'table-action-done' => function () {
        unset($this->stats);
    },
]);

$stats = computed(function () {
    $routes = TransportRoute::query()->with('vehicle')->withCount('activeAssignments')->get();
    $withVehicle = $routes->filter(fn (TransportRoute $route) => $route->vehicle);

    return [
        'routes' => $routes->count(),
        'vehicles' => TransportVehicle::query()->where('status', RecordStatus::Active)->count(),
        'riders' => StudentTransport::query()->active()->count(),
        'seats' => $withVehicle->sum(fn (TransportRoute $route) => $route->vehicle->capacity),
        'taken' => $withVehicle->sum('active_assignments_count'),
        'full' => $withVehicle->filter(fn (TransportRoute $route) => $route->active_assignments_count >= $route->vehicle->capacity)->count(),
    ];
});

?>

@php
    $stats = $this->stats;
    $tabs = [
        'routes' => __('Routes'),
        'vehicles' => __('Vehicles'),
        'students' => __('Students').($stats['riders'] ? ' ('.$stats['riders'].')' : ''),
    ];
    $create = match ($tab) {
        'vehicles' => ['forms.transport-vehicle', __('Add a vehicle'), 'md'],
        'students' => ['forms.student-transport', __('Give transport'), 'md'],
        default => ['forms.transport-route', __('New route'), 'lg'],
    };
@endphp

<div>
    <x-page-header :title="__('Transport')" :breadcrumbs="[__('School Admin') => route('admin.dashboard'), __('Operations') => null, __('Transport') => null]">
        @can('transport.manage')
            <x-slot:actions>
                <button type="button" class="btn btn-primary" wire:click="$dispatch('open-modal', @js(['component' => $create[0], 'size' => $create[2]]))">
                    <i class="ri-add-line me-1"></i>{{ $create[1] }}
                </button>
            </x-slot:actions>
        @endcan
    </x-page-header>

    <div class="row g-4 mb-4">
        <div class="col-6 col-xl-3">
            <x-stat-card :label="__('Routes')" :value="$stats['routes']" icon="ri-route-line" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card :label="__('Vehicles in service')" :value="$stats['vehicles']" icon="ri-bus-2-line" color="info" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card :label="__('Students on transport')" :value="$stats['riders']" icon="ri-group-line" color="success" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card :label="__('Seats free')" :value="max(0, $stats['seats'] - $stats['taken'])" icon="ri-armchair-line"
                         :color="$stats['full'] > 0 ? 'warning' : 'secondary'"
                         :hint="$stats['full'] > 0 ? trans_choice(':count route is full|:count routes are full', $stats['full'], ['count' => $stats['full']]) : __('of :seats seats', ['seats' => $stats['seats']])" />
        </div>
    </div>

    <ul class="nav nav-tabs mb-4" role="tablist">
        @foreach ($tabs as $key => $label)
            <li class="nav-item" role="presentation">
                <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        @class(['nav-link', 'active' => $tab === $key])>{{ $label }}</button>
            </li>
        @endforeach
    </ul>

    @switch($tab)
        @case('vehicles')
            <livewire:components.data-table :table="TransportVehiclesTable::class" wire:key="transport-vehicles" />
            @break
        @case('students')
            <livewire:components.data-table :table="StudentTransportsTable::class" wire:key="transport-students" />
            @break
        @default
            <livewire:components.data-table :table="TransportRoutesTable::class" wire:key="transport-routes" />
    @endswitch
</div>
