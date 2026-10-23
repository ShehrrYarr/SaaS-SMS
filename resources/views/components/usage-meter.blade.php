{{--
    How much of a plan limit is used.
        <x-usage-meter :label="__('Students')" :used="320" :limit="300" />
    A null limit means unlimited.
--}}
@props(['label', 'used', 'limit' => null])

@php
    $percent = $limit ? min(100, (int) round($used / max(1, $limit) * 100)) : null;
    $color = match (true) {
        $percent === null => 'primary',
        $used > $limit => 'danger',
        $percent >= 90 => 'warning',
        default => 'success',
    };
@endphp

<div {{ $attributes }}>
    <div class="d-flex justify-content-between align-items-baseline mb-1">
        <span class="fw-medium">{{ $label }}</span>
        <span class="text-muted fs-13">
            <span @class(['fw-semibold', 'text-danger' => $color === 'danger', 'text-body' => $color !== 'danger'])>{{ number_format($used) }}</span>
            / {{ $limit === null ? __('Unlimited') : number_format($limit) }}
        </span>
    </div>
    <div class="progress" style="height: 6px" role="progressbar" aria-label="{{ $label }}"
         aria-valuenow="{{ $used }}" aria-valuemin="0" @if ($limit) aria-valuemax="{{ $limit }}" @endif>
        <div class="progress-bar bg-{{ $color }}" style="width: {{ $percent ?? 100 }}%; {{ $percent === null ? 'opacity: .25' : '' }}"></div>
    </div>
    @if ($limit !== null && $used > $limit)
        <small class="text-danger d-block mt-1">{{ __('Over the plan limit: no more can be added until it is raised.') }}</small>
    @endif
</div>
