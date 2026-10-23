<?php

/*
 * The school notice board: live notices addressed to the signed-in user,
 * pinned first. Open to every account; shown inside the user's home portal.
 */

use App\Models\Notice;
use App\Support\Portal;
use Illuminate\Support\Str;

use function Livewire\Volt\{computed, layout, mount, state, title, updated, usesPagination};

layout('layouts.app');
title(__('Notice board'));

usesPagination(theme: 'bootstrap');

state(['search' => '']);

mount(function () {
    if ($portal = auth()->user()->accessiblePortals()[0] ?? null) {
        Portal::setCurrent($portal);
    }
});

updated(['search' => fn () => $this->resetPage()]);

$notices = computed(fn () => Notice::query()
    ->live()
    ->visibleTo(auth()->user())
    ->when(trim($this->search) !== '', function ($query) {
        $term = '%'.addcslashes(trim($this->search), '%_\\').'%';
        $query->where(fn ($query) => $query->where('title', 'like', $term)->orWhere('body', 'like', $term));
    })
    ->forBoard()
    ->paginate(10));

?>

<div>
    <x-page-header :title="__('Notice board')" :breadcrumbs="[tenant()->name => route('tenant.home'), __('Notice board') => null]">
        @can('notices.view')
            <x-slot:actions>
                <a href="{{ route('admin.notices.index') }}" wire:navigate class="btn btn-light"><i class="ri-settings-4-line me-1"></i>{{ __('Manage notices') }}</a>
            </x-slot:actions>
        @endcan
    </x-page-header>

    <div class="row justify-content-center">
        <div class="col-xl-9">
            <div class="form-icon mb-4">
                <input type="search" class="form-control form-control-icon" placeholder="{{ __('Search notices…') }}" aria-label="{{ __('Search notices') }}"
                       wire:model.live.debounce.350ms="search">
                <i class="ri-search-2-line text-muted"></i>
            </div>

            @forelse ($this->notices as $notice)
                <a href="{{ route('tenant.notices.show', $notice->id) }}" wire:navigate wire:key="notice-{{ $notice->id }}"
                   class="card mb-3 text-reset text-decoration-none notice-card">
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-3">
                            <span class="stat-icon bg-{{ $notice->is_pinned ? 'warning' : 'primary' }}-subtle text-{{ $notice->is_pinned ? 'warning' : 'primary' }} rounded-2 d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 2.5rem; height: 2.5rem">
                                <i class="{{ $notice->is_pinned ? 'ri-pushpin-2-fill' : 'ri-megaphone-line' }}"></i>
                            </span>
                            <div class="min-w-0 flex-grow-1">
                                <h5 class="mb-1 text-body">{{ $notice->title }}</h5>
                                <small class="text-muted">
                                    {{ tenant()->localTime($notice->published_at)->format(tenant()->setting('date_format')) }}
                                    @if ($notice->is_pinned) · {{ __('Pinned') }} @endif
                                    @if ($notice->attachment) · <i class="ri-attachment-2"></i> {{ __('Attachment') }} @endif
                                </small>
                                <p class="mb-0 mt-2 text-muted">{{ Str::limit(preg_replace('/\s+/', ' ', $notice->body), 220) }}</p>
                            </div>
                        </div>
                    </div>
                </a>
            @empty
                <div class="card mb-0">
                    <x-empty-state icon="ri-megaphone-line"
                                   :title="trim($search) !== '' ? __('No matching notices') : __('No notices right now')"
                                   :message="trim($search) !== '' ? __('Try a different search.') : __('Announcements from the school will appear here.')" />
                </div>
            @endforelse

            @if ($this->notices->hasPages())
                <div class="data-table-pagination mt-4">{{ $this->notices->links() }}</div>
            @endif
        </div>
    </div>
</div>
