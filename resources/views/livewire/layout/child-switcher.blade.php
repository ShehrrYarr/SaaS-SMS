<?php

/*
 * Parent portal header: which child the pages show. Picking another child
 * remembers it for the session and reloads the current page (wire:navigate,
 * no full page load).
 */

use App\Support\SelfService;

use function Livewire\Volt\{computed, state};

// The page this header was rendered on, to reload after a switch.
state(['url' => fn () => request()->fullUrl()])->locked();

$children = computed(fn () => SelfService::children(auth()->user()));

$current = computed(fn () => SelfService::student());

$switch = function (int $child) {
    abort_unless(SelfService::select(auth()->user(), $child), 404);

    // A ?child= in the address would pick the old child again.
    $parts = parse_url($this->url);
    parse_str($parts['query'] ?? '', $query);
    unset($query['child']);

    $this->redirect(strtok($this->url, '?').($query ? '?'.http_build_query($query) : ''), navigate: true);
};

?>

<div class="dropdown features-dropdown child-switcher">
    @if ($this->current)
        <button type="button" class="btn btn-sm btn-subtle-info hstack gap-2" @if ($this->children->count() > 1) data-bs-toggle="dropdown" aria-expanded="false" @endif
                aria-label="{{ __('Viewing :name. Switch child', ['name' => $this->current->user?->name]) }}">
            <span class="avatar-item avatar-xs avatar-title bg-info text-white rounded-circle fs-11 fw-semibold" style="width: 1.5rem; height: 1.5rem">{{ $this->current->user?->initials() }}</span>
            <span class="d-none d-md-inline text-truncate" style="max-width: 10rem">{{ $this->current->user?->name }}</span>
            @if ($this->children->count() > 1)<i class="ri-arrow-down-s-line"></i>@endif
        </button>
        @if ($this->children->count() > 1)
            <div class="dropdown-menu dropdown-menu-end">
                <span class="dropdown-header fs-12">{{ __('Switch child') }}</span>
                @foreach ($this->children as $child)
                    <button type="button" wire:key="child-{{ $child->id }}" wire:click="switch({{ $child->id }})"
                            @class(['dropdown-item hstack gap-2', 'active' => $child->id === $this->current->id]) @if ($child->id === $this->current->id) aria-current="true" @endif>
                        <span class="avatar-item avatar-xs avatar-title bg-info-subtle text-info rounded-circle fs-11 fw-semibold flex-shrink-0" style="width: 1.75rem; height: 1.75rem">{{ $child->user?->initials() }}</span>
                        <span class="min-w-0">
                            <span class="d-block text-truncate">{{ $child->user?->name }}</span>
                            <small class="d-block text-muted">{{ $child->placementLabel() ?? __('Not placed in a class') }}</small>
                        </span>
                    </button>
                @endforeach
            </div>
        @endif
    @endif
</div>
