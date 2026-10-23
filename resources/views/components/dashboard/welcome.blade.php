{{-- Greeting card shared by the portal dashboards. --}}
@props(['subtitle' => null])

@php
    $user = auth()->user();
    $hour = now()->timezone(tenant()?->setting('timezone') ?? config('app.timezone'))->hour;
    $greeting = $hour < 12 ? __('Good morning') : ($hour < 17 ? __('Good afternoon') : __('Good evening'));
@endphp

<div {{ $attributes->class('card mb-0 h-100') }}>
    <div class="card-body d-flex align-items-center gap-4">
        <span class="avatar-item avatar-lg avatar-title bg-primary-subtle text-primary fs-4 fw-semibold flex-shrink-0">{{ $user->initials() }}</span>
        <div class="min-w-0">
            <h5 class="mb-1">{{ $greeting }}, {{ str($user->name)->before(' ') }}</h5>
            <p class="text-muted mb-0">{{ $subtitle ?? $user->roleLabel().' · '.tenant()?->name }}</p>
        </div>
    </div>
    @if (trim($slot) !== '')
        <div class="card-footer border-top">{{ $slot }}</div>
    @endif
</div>
