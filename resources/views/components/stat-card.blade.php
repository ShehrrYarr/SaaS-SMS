@props(['label', 'value', 'icon', 'color' => 'primary', 'hint' => null])

<div {{ $attributes->class('card stat-card h-100 mb-0') }}>
    <div class="card-body d-flex align-items-center gap-4">
        <span class="stat-icon bg-{{ $color }}-subtle text-{{ $color }}">
            <i class="{{ $icon }}"></i>
        </span>
        <div class="min-w-0">
            <p class="text-muted mb-1 text-truncate">{{ $label }}</p>
            <h4 class="mb-0 fw-semibold">{{ $value }}</h4>
            @if ($hint)
                <small class="text-muted">{{ $hint }}</small>
            @endif
        </div>
    </div>
</div>
