{{--
    The newest live notices addressed to the signed-in user, for dashboards.
        <x-notices.latest />
--}}
@props(['limit' => 5])

@php
    $school = tenant();
    $notices = App\Models\Notice::query()->live()->visibleTo(auth()->user())->forBoard()->limit($limit)->get();
@endphp

<div {{ $attributes->class('card mb-0 h-100') }}>
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <h5 class="card-title mb-0">{{ __('Notice board') }}</h5>
        <a href="{{ route('tenant.notices.index') }}" wire:navigate class="btn btn-sm btn-light">{{ __('View all') }}</a>
    </div>
    @if ($notices->isEmpty())
        <x-empty-state icon="ri-megaphone-line" :title="__('No notices right now')" />
    @else
        <ul class="list-group list-group-flush">
            @foreach ($notices as $notice)
                <li class="list-group-item">
                    <a href="{{ route('tenant.notices.show', $notice->id) }}" wire:navigate class="d-flex gap-3 text-reset text-decoration-none">
                        <i class="{{ $notice->is_pinned ? 'ri-pushpin-2-fill text-warning' : 'ri-megaphone-line text-primary' }} fs-5 lh-1 mt-1"></i>
                        <span class="min-w-0">
                            <span class="d-block fw-medium text-body text-truncate">{{ $notice->title }}</span>
                            <small class="text-muted">{{ $school->localTime($notice->published_at)->format($school->setting('date_format')) }}</small>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
