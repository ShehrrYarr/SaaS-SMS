{{--
    Attendance so far this year: the rate and a breakdown chart.
    :stats is StudentOverview::attendance(); :url links to the full page.
--}}
@use('App\Enums\AttendanceStatus')

@props(['stats', 'url' => null])

<div {{ $attributes->class('card mb-0 h-100') }}>
    <div class="card-header hstack gap-2">
        <h5 class="card-title mb-0 flex-grow-1">{{ __('Attendance this year') }}</h5>
        @if ($url)<a href="{{ $url }}" wire:navigate class="fs-13">{{ __('Details') }}</a>@endif
    </div>
    <div class="card-body">
        @if ($stats['marked'] === 0)
            <x-empty-state icon="ri-calendar-check-line" :title="__('No attendance marked yet')" class="py-4" />
        @else
            <div class="hstack gap-3 mb-2">
                <span class="fs-2 fw-semibold">{{ $stats['rate'] !== null ? $stats['rate'].'%' : '—' }}</span>
                <x-family.attendance-line :present="$stats['present']" :school-days="$stats['school_days']" :excused="$stats['excused']" class="text-muted fs-13" />
            </div>
            <x-chart height="200" :options="[
                'chart' => ['type' => 'donut'],
                'series' => collect(AttendanceStatus::cases())->map(fn ($status) => $stats['counts'][$status->value] ?? 0)->all(),
                'labels' => collect(AttendanceStatus::cases())->map->label()->all(),
                'colors' => collect(AttendanceStatus::cases())->map(fn ($status) => '--bs-'.$status->color())->all(),
                'plotOptions' => ['pie' => ['donut' => ['size' => '62%']]],
            ]" />
        @endif
    </div>
</div>
