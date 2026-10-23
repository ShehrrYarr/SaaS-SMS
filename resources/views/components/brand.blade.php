@props(['name', 'logo' => null, 'compact' => false, 'size' => 'md'])

@php
    $initials = collect(preg_split('/\s+/', trim($name)))
        ->filter()
        ->take(2)
        ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');
@endphp

<span {{ $attributes->class('d-inline-flex align-items-center gap-2') }}>
    <span @class(['brand-mark', 'brand-mark-lg' => $size === 'lg'])>
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $name }}">
        @else
            {{ $initials }}
        @endif
    </span>
    @unless ($compact)
        <span class="brand-name">{{ $name }}</span>
    @endunless
</span>
