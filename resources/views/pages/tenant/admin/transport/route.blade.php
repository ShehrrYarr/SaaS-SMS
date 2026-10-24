<?php

/*
 * One bus route: its vehicle and driver, the stops in order with how many
 * students board at each, and the riders. Printable as a roster for the
 * driver.
 */

use App\Models\StudentTransport;
use App\Models\TransportRoute;
use App\Support\Billing\Money;
use App\Tables\StudentTransportsTable;

use function Livewire\Volt\{computed, layout, mount, on, state, title};

layout('layouts.app');
title(fn () => $this->transportRoute->name);

state(['route' => null])->locked();

mount(function (int $transportRoute) {
    $this->authorize('transport.view');
    $this->route = TransportRoute::query()->findOrFail($transportRoute)->id;
});

on([
    'transport-updated' => function () {
        unset($this->transportRoute, $this->riders);
    },
    'table-action-done' => function () {
        unset($this->transportRoute, $this->riders);
    },
]);

$transportRoute = computed(fn () => TransportRoute::query()->with('vehicle')->findOrFail($this->route));

// Current riders in stop order, for the stop list and the printed roster.
$riders = computed(function () {
    $order = $this->transportRoute->stopList()->pluck('name')->map(fn ($name) => mb_strtolower($name))->flip();

    return StudentTransport::query()->active()
        ->with(['student.user', 'student.schoolClass', 'student.section', 'student.parents.user'])
        ->where('transport_route_id', $this->route)
        ->get()
        ->sortBy(fn (StudentTransport $rider) => [$order[mb_strtolower((string) $rider->pickup_point)] ?? 999, mb_strtolower((string) $rider->student?->user?->name)])
        ->values();
});

?>

@php
    $route = $this->transportRoute;
    $vehicle = $route->vehicle;
    $riders = $this->riders;
    $byStop = $riders->groupBy(fn ($rider) => mb_strtolower((string) $rider->pickup_point));
    $seats = $route->seats();
@endphp

<div>
    <x-page-header :title="$route->name" :breadcrumbs="[__('School Admin') => route('admin.dashboard'), __('Transport') => route('admin.transport.index'), $route->name => null]">
        <x-slot:actions>
            <button type="button" class="btn btn-light" onclick="window.print()"><i class="ri-printer-line me-1"></i>{{ __('Print roster') }}</button>
            @can('transport.manage')
                <button type="button" class="btn btn-light" wire:click="$dispatch('open-modal', @js(['component' => 'forms.transport-route', 'params' => ['route' => $route->id], 'size' => 'lg']))">
                    <i class="ri-pencil-line me-1"></i>{{ __('Edit route') }}
                </button>
                <button type="button" class="btn btn-primary" wire:click="$dispatch('open-modal', @js(['component' => 'forms.student-transport', 'params' => ['route' => $route->id]]))">
                    <i class="ri-user-add-line me-1"></i>{{ __('Add a student') }}
                </button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="card mb-0 h-100">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('Route') }}</h5></div>
                <div class="card-body">
                    <dl class="detail-list mb-0">
                        <dt>{{ __('From – to') }}</dt><dd>{{ $route->endpoints() ?? '—' }}</dd>
                        <dt>{{ __('Monthly fare') }}</dt><dd>{{ Money::school($route->fare) }}</dd>
                        <dt>{{ __('Vehicle') }}</dt><dd>{{ $vehicle?->label() ?? __('None yet') }}</dd>
                        <dt>{{ __('Driver') }}</dt>
                        <dd>
                            {{ $vehicle?->driver_name ?: '—' }}
                            @if ($vehicle?->driver_phone)<span class="d-block text-muted"><i class="ri-phone-line me-1"></i>{{ $vehicle->driver_phone }}</span>@endif
                        </dd>
                        <dt>{{ __('Students') }}</dt>
                        <dd>
                            @if ($seats === null)
                                {{ $riders->count() }}
                            @else
                                <span @class(['text-danger fw-medium' => $riders->count() >= $seats])>{{ __(':riders of :seats seats', ['riders' => $riders->count(), 'seats' => $seats]) }}</span>
                            @endif
                        </dd>
                    </dl>
                    @if ($route->description)
                        <p class="text-muted fs-13 text-pre-line mt-3 mb-0">{{ $route->description }}</p>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card mb-0 h-100">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('Stops') }}</h5></div>
                @if ($route->stopList()->isEmpty())
                    <x-empty-state icon="ri-map-pin-line" :title="__('No stops listed')" :message="__('Edit the route to add its stops and pickup times.')" class="py-6" />
                @else
                    <ol class="list-group list-group-flush list-group-numbered">
                        @foreach ($route->stopList() as $stop)
                            @php($boarding = $byStop->get(mb_strtolower($stop['name']), collect()))
                            <li class="list-group-item d-flex align-items-start gap-3" wire:key="stop-{{ $loop->index }}">
                                <div class="flex-grow-1 min-w-0">
                                    <span class="fw-medium">{{ $stop['name'] }}</span>
                                    @if ($boarding->isNotEmpty())
                                        <small class="d-block text-muted text-truncate">{{ $boarding->map(fn ($rider) => $rider->student?->user?->name)->implode(', ') }}</small>
                                    @endif
                                </div>
                                @if ($stop['time'])
                                    <span class="text-muted text-nowrap"><i class="ri-time-line me-1"></i>{{ $route->pickupTime($stop['name']) }}</span>
                                @endif
                                <span class="badge bg-light text-body border">{{ trans_choice(':count student|:count students', $boarding->count(), ['count' => $boarding->count()]) }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        </div>
    </div>

    <div class="d-print-none">
        <livewire:components.data-table :table="StudentTransportsTable::class" :params="['route' => $route->id]" wire:key="route-riders-{{ $route->id }}" />
    </div>

    {{-- Printed roster: who boards where, with a parent to call. --}}
    <div class="d-none d-print-block">
        <h5 class="mb-3">{{ __('Roster') }} · {{ $route->name }}</h5>
        <table class="table table-sm table-bordered">
            <thead>
                <tr><th>#</th><th>{{ __('Student') }}</th><th>{{ __('Class') }}</th><th>{{ __('Stop') }}</th><th>{{ __('Pickup') }}</th><th>{{ __('Parent\'s phone') }}</th></tr>
            </thead>
            <tbody>
                @foreach ($riders as $rider)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $rider->student?->user?->name }}</td>
                        <td>{{ $rider->student?->placementLabel() }}</td>
                        <td>{{ $rider->pickup_point }}</td>
                        <td>{{ $route->pickupTime($rider->pickup_point) }}</td>
                        <td>{{ $rider->student?->parents->map(fn ($parent) => $parent->user?->phone)->filter()->first() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
