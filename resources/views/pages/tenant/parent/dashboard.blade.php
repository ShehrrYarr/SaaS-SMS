<?php

use function Livewire\Volt\{computed, layout, title};

layout('layouts.app');
title(__('Dashboard'));

$children = computed(fn () => auth()->user()->parentProfile?->children()->with(['user', 'schoolClass', 'section'])->get() ?? collect());

?>

<div>
    <x-page-header :title="__('Dashboard')" :breadcrumbs="[__('Parent') => null, __('Dashboard') => null]" />

    <div class="row g-4">
        <div class="col-xl-5"><x-dashboard.welcome /></div>
        <div class="col-xl-7">
            <div class="card mb-0 h-100">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('My children') }}</h5></div>
                @if ($this->children->isEmpty())
                    <x-empty-state icon="ri-parent-line" :title="__('No children linked yet')" :message="__('Ask the school office to link your children to your account.')" />
                @else
                    <ul class="list-group list-group-flush">
                        @foreach ($this->children as $child)
                            <li class="list-group-item hstack gap-3" wire:key="child-{{ $child->id }}">
                                <span class="avatar-item avatar avatar-title bg-info-subtle text-info fw-semibold">{{ $child->user->initials() }}</span>
                                <div class="min-w-0">
                                    <div class="fw-medium">{{ $child->user->name }}</div>
                                    <small class="text-muted">{{ trim(($child->schoolClass?->name ?? '').' '.($child->section?->name ?? '')) ?: __('Not placed in a class yet') }}</small>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

    <div class="mt-4">
        <x-notices.latest />
    </div>
</div>
