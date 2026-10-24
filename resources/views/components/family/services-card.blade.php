{{--
    School services at a glance: the bus (route, stop, pickup time), the
    hostel room and library books. Each is null when the school's plan
    doesn't include it; transport and hostel are ['current' => ?model].
    :portal is student or parent.
--}}
@props(['transport' => null, 'hostel' => null, 'library' => null, 'portal'])

@php
    $format = tenant()->setting('date_format');
    $today = tenant()->today();
@endphp

<div {{ $attributes->class('card mb-0 h-100') }}>
    <div class="card-header"><h5 class="card-title mb-0">{{ __('School services') }}</h5></div>
    <ul class="list-group list-group-flush">
        @if ($transport !== null)
            @php($transport = $transport['current'])
            <li class="list-group-item d-flex gap-3">
                <span class="avatar-item avatar avatar-title bg-primary-subtle text-primary fs-5 flex-shrink-0"><i class="ri-bus-2-line"></i></span>
                <div class="min-w-0 flex-grow-1">
                    @if ($transport)
                        <span class="fw-medium d-block text-truncate">{{ $transport->route?->name }}</span>
                        <small class="text-muted d-block">
                            {{ collect([$transport->pickup_point, $transport->route?->pickupTime($transport->pickup_point)])->filter()->implode(' · ') ?: __('No stop set') }}
                        </small>
                        @if ($transport->route?->vehicle?->driver_phone)
                            <small class="d-block"><i class="ri-phone-line me-1 text-muted"></i>{{ __('Driver :name', ['name' => $transport->route->vehicle->driver_name]) }} · {{ $transport->route->vehicle->driver_phone }}</small>
                        @endif
                    @else
                        <span class="d-block">{{ __('Not on school transport') }}</span>
                    @endif
                </div>
                <a href="{{ route($portal.'.services') }}" wire:navigate class="fs-13 text-nowrap align-self-center">{{ __('Details') }}</a>
            </li>
        @endif

        @if ($hostel !== null)
            @php($hostel = $hostel['current'])
            <li class="list-group-item d-flex gap-3">
                <span class="avatar-item avatar avatar-title bg-info-subtle text-info fs-5 flex-shrink-0"><i class="ri-hotel-bed-line"></i></span>
                <div class="min-w-0 flex-grow-1">
                    @if ($hostel)
                        <span class="fw-medium d-block text-truncate">{{ $hostel->room?->building?->name }}</span>
                        <small class="text-muted d-block">{{ collect([$hostel->room?->label(false), $hostel->bed_number ? __('Bed :bed', ['bed' => $hostel->bed_number]) : null])->filter()->implode(' · ') }}</small>
                    @else
                        <span class="d-block">{{ __('Not staying in the hostel') }}</span>
                    @endif
                </div>
                <a href="{{ route($portal.'.services') }}" wire:navigate class="fs-13 text-nowrap align-self-center">{{ __('Details') }}</a>
            </li>
        @endif

        @if ($library !== null)
            @php($next = $library['out']->first())
            <li class="list-group-item d-flex gap-3">
                <span @class(['avatar-item avatar avatar-title fs-5 flex-shrink-0', 'bg-danger-subtle text-danger' => $library['overdue'] > 0, 'bg-success-subtle text-success' => $library['overdue'] === 0])><i class="ri-book-2-line"></i></span>
                <div class="min-w-0 flex-grow-1">
                    <span class="fw-medium d-block">{{ trans_choice(':count library book out|:count library books out', $library['out']->count(), ['count' => $library['out']->count()]) }}</span>
                    @if ($library['overdue'] > 0)
                        <small class="text-danger d-block">{{ trans_choice(':count overdue — please return it|:count overdue — please return them', $library['overdue'], ['count' => $library['overdue']]) }}</small>
                    @elseif ($next)
                        <small class="text-muted d-block text-truncate">{{ __(':title due :date', ['title' => $next->book?->title, 'date' => $next->due_on->format($format)]) }}</small>
                    @endif
                    @if ($library['owed'] > 0)
                        <small class="text-warning d-block">{{ __('Fines owed: :amount', ['amount' => App\Support\Billing\Money::school($library['owed'])]) }}</small>
                    @endif
                </div>
                <a href="{{ route($portal.'.library') }}" wire:navigate class="fs-13 text-nowrap align-self-center">{{ __('Books') }}</a>
            </li>
        @endif
    </ul>
</div>
