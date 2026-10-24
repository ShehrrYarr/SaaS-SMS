<?php

/*
 * Student and parent portals: school transport (route, stop, pickup time,
 * bus and driver) and hostel (room, bed and warden), with earlier ones.
 */

use App\Models\StudentHostel;
use App\Models\StudentTransport;
use App\Services\Portals\StudentOverview;
use App\Support\Billing\Money;
use App\Support\SelfService;

use function Livewire\Volt\{computed, layout, title};

layout('layouts.app');
title(__('Transport & hostel'));

$student = computed(fn () => SelfService::student());

$transport = computed(fn () => StudentOverview::enabled('transport.view') && $this->student ? [
    'current' => app(StudentOverview::class)->transport($this->student),
    'earlier' => StudentTransport::query()->where('student_profile_id', $this->student->id)->where('status', 'ended')
        ->with(['route' => fn ($query) => $query->withTrashed()])->latest('start_date')->limit(5)->get(),
] : null);

$hostel = computed(fn () => StudentOverview::enabled('hostel.view') && $this->student ? [
    'current' => app(StudentOverview::class)->hostel($this->student),
    'earlier' => StudentHostel::query()->where('student_profile_id', $this->student->id)->where('status', 'ended')
        ->with(['room' => fn ($query) => $query->withTrashed()->with(['building' => fn ($query) => $query->withTrashed()])])->latest('check_in')->limit(5)->get(),
] : null);

?>

@php($format = tenant()->setting('date_format'))

<div>
    <x-page-header :title="__('Transport & hostel')" :breadcrumbs="SelfService::breadcrumbs([__('Transport & hostel') => null])" />

    @if (! $this->student)
        <x-family.missing />
    @else
        <div class="row g-4">
            @if ($this->transport !== null)
                @php($ride = $this->transport['current'])
                <div class="col-xl-6">
                    <div class="card mb-0 h-100">
                        <div class="card-header hstack gap-2">
                            <i class="ri-bus-2-line text-primary fs-5"></i>
                            <h5 class="card-title mb-0">{{ __('School transport') }}</h5>
                        </div>
                        @if (! $ride)
                            <x-empty-state icon="ri-bus-line" :title="__('Not on school transport')" :message="__('To use the school bus, ask the school office.')" class="py-6" />
                        @else
                            @php($route = $ride->route)
                            @php($vehicle = $route?->vehicle)
                            <div class="card-body">
                                <div class="row g-3 mb-3">
                                    <div class="col-sm-6">
                                        <small class="text-muted d-block">{{ __('Route') }}</small>
                                        <span class="fw-semibold">{{ $route?->name }}</span>
                                        @if ($route?->endpoints())<small class="d-block text-muted">{{ $route->endpoints() }}</small>@endif
                                    </div>
                                    <div class="col-sm-6">
                                        <small class="text-muted d-block">{{ __('Pickup') }}</small>
                                        <span class="fw-semibold">{{ $ride->pickup_point ?: '—' }}</span>
                                        @if ($time = $route?->pickupTime($ride->pickup_point))<small class="d-block text-muted"><i class="ri-time-line me-1"></i>{{ $time }}</small>@endif
                                    </div>
                                    <div class="col-sm-6">
                                        <small class="text-muted d-block">{{ __('Bus') }}</small>
                                        <span>{{ $vehicle?->label() ?? '—' }}</span>
                                    </div>
                                    <div class="col-sm-6">
                                        <small class="text-muted d-block">{{ __('Driver') }}</small>
                                        <span>{{ $vehicle?->driver_name ?: '—' }}</span>
                                        @if ($vehicle?->driver_phone)
                                            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $vehicle->driver_phone) }}" class="d-block fs-13"><i class="ri-phone-line me-1"></i>{{ $vehicle->driver_phone }}</a>
                                        @endif
                                    </div>
                                    <div class="col-sm-6">
                                        <small class="text-muted d-block">{{ __('Since') }}</small>
                                        <span>{{ $ride->start_date->format($format) }}</span>
                                    </div>
                                    @if ((float) $route?->fare > 0)
                                        <div class="col-sm-6">
                                            <small class="text-muted d-block">{{ __('Monthly fare') }}</small>
                                            <span>{{ Money::school($route->fare) }}</span>
                                        </div>
                                    @endif
                                </div>

                                @if ($route && $route->stopList()->isNotEmpty())
                                    <h6 class="fs-13 text-muted text-uppercase mb-2">{{ __('Stops') }}</h6>
                                    <ol class="list-group list-group-numbered">
                                        @foreach ($route->stopList() as $stop)
                                            @php($mine = mb_strtolower($stop['name']) === mb_strtolower((string) $ride->pickup_point))
                                            <li @class(['list-group-item d-flex justify-content-between align-items-center py-2', 'list-group-item-primary fw-medium' => $mine])>
                                                <span class="ms-2 me-auto">{{ $stop['name'] }} @if ($mine)<span class="badge bg-primary ms-1">{{ __('Pickup') }}</span>@endif</span>
                                                <span class="text-muted fs-13">{{ $route->pickupTime($stop['name']) }}</span>
                                            </li>
                                        @endforeach
                                    </ol>
                                @endif
                            </div>
                        @endif
                        @if ($this->transport['earlier']->isNotEmpty())
                            <div class="card-footer">
                                <small class="text-muted d-block mb-1">{{ __('Earlier') }}</small>
                                @foreach ($this->transport['earlier'] as $past)
                                    <small class="d-block">{{ $past->route?->name }} · {{ $past->start_date->format($format) }} – {{ $past->end_date?->format($format) }}</small>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            @if ($this->hostel !== null)
                @php($stay = $this->hostel['current'])
                <div class="col-xl-6">
                    <div class="card mb-0 h-100">
                        <div class="card-header hstack gap-2">
                            <i class="ri-hotel-bed-line text-primary fs-5"></i>
                            <h5 class="card-title mb-0">{{ __('Hostel') }}</h5>
                        </div>
                        @if (! $stay)
                            <x-empty-state icon="ri-hotel-bed-line" :title="__('Not staying in the hostel')" :message="__('For a hostel place, ask the school office.')" class="py-6" />
                        @else
                            @php($room = $stay->room)
                            @php($warden = $room?->building?->warden?->user)
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-sm-6">
                                        <small class="text-muted d-block">{{ __('Building') }}</small>
                                        <span class="fw-semibold">{{ $room?->building?->name }}</span>
                                        @if ($room?->building?->address)<small class="d-block text-muted">{{ $room->building->address }}</small>@endif
                                    </div>
                                    <div class="col-sm-6">
                                        <small class="text-muted d-block">{{ __('Room') }}</small>
                                        <span class="fw-semibold">{{ $room?->label(false) }}{{ $stay->bed_number ? ' · '.__('Bed :bed', ['bed' => $stay->bed_number]) : '' }}</span>
                                        @if ($room?->floor)<small class="d-block text-muted">{{ __('Floor: :floor', ['floor' => $room->floor]) }}</small>@endif
                                    </div>
                                    <div class="col-sm-6">
                                        <small class="text-muted d-block">{{ __('Warden') }}</small>
                                        <span>{{ $warden?->name ?? '—' }}</span>
                                        @if ($warden?->phone)
                                            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $warden->phone) }}" class="d-block fs-13"><i class="ri-phone-line me-1"></i>{{ $warden->phone }}</a>
                                        @endif
                                    </div>
                                    <div class="col-sm-6">
                                        <small class="text-muted d-block">{{ __('Since') }}</small>
                                        <span>{{ $stay->check_in->format($format) }}</span>
                                    </div>
                                    @if ((float) $room?->fee > 0)
                                        <div class="col-sm-6">
                                            <small class="text-muted d-block">{{ __('Monthly fee') }}</small>
                                            <span>{{ Money::school($room->fee) }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                        @if ($this->hostel['earlier']->isNotEmpty())
                            <div class="card-footer">
                                <small class="text-muted d-block mb-1">{{ __('Earlier') }}</small>
                                @foreach ($this->hostel['earlier'] as $past)
                                    <small class="d-block">{{ $past->room?->label() }} · {{ $past->check_in->format($format) }} – {{ $past->check_out?->format($format) }}</small>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
