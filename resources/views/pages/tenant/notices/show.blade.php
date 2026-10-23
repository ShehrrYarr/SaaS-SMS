<?php

use App\Models\Notice;
use App\Support\Portal;

use function Livewire\Volt\{computed, layout, mount, on, state, title};

layout('layouts.app');
title(fn () => $this->record->title);

state(['noticeId' => null])->locked();

// Reload after the edit form saves.
on(['refresh-tables' => function () {
    unset($this->record);
}]);

$record = computed(fn () => Notice::query()->with(['schoolClass', 'creator'])->findOrFail($this->noticeId));

mount(function (int $notice) {
    $record = Notice::query()->findOrFail($notice);
    abort_unless($record->canBeReadBy(auth()->user()), 404);

    $this->noticeId = $record->id;

    if ($portal = auth()->user()->accessiblePortals()[0] ?? null) {
        Portal::setCurrent($portal);
    }
});

?>

@php
    $notice = $this->record;
    $school = tenant();
    $state = $notice->state();
@endphp

<div>
    <x-page-header :title="$notice->title" :breadcrumbs="[$school->name => route('tenant.home'), __('Notice board') => route('tenant.notices.index'), __('Notice') => null]">
        @can('notices.edit')
            <x-slot:actions>
                <button type="button" class="btn btn-light" wire:click="$dispatch('open-modal', { component: 'forms.notice', params: { notice: {{ $notice->id }} }, size: 'lg' })">
                    <i class="ri-pencil-line me-1"></i>{{ __('Edit') }}
                </button>
            </x-slot:actions>
        @endcan
    </x-page-header>

    <div class="row justify-content-center">
        <div class="col-xl-9">
            @unless ($state === App\Enums\NoticeState::Live)
                <div class="alert alert-{{ $state->color() }} d-flex gap-2" role="status">
                    <i class="ri-eye-off-line fs-5 lh-1"></i>
                    <div>{{ __('This notice is :state, so only notice managers can see it.', ['state' => Str::lower($state->label())]) }}</div>
                </div>
            @endunless

            <article class="card mb-0">
                <div class="card-header d-flex flex-wrap align-items-center gap-2">
                    <span class="badge bg-{{ $notice->target_audience->color() }}-subtle text-{{ $notice->target_audience->color() }}">{{ $notice->target_audience->label() }}</span>
                    @if ($notice->schoolClass)
                        <span class="badge bg-light text-body border">{{ $notice->schoolClass->name }}</span>
                    @endif
                    @if ($notice->is_pinned)
                        <span class="badge bg-warning-subtle text-warning"><i class="ri-pushpin-2-fill me-1"></i>{{ __('Pinned') }}</span>
                    @endif
                    <small class="text-muted ms-auto">
                        @if ($notice->published_at)
                            {{ $school->localTime($notice->published_at)->format($school->setting('date_format').', '.$school->setting('time_format')) }}
                        @else
                            {{ __('Not published') }}
                        @endif
                        @if ($notice->creator) · {{ $notice->creator->name }} @endif
                    </small>
                </div>
                <div class="card-body notice-body">{!! nl2br(e($notice->body)) !!}</div>
                @if ($notice->attachment)
                    <div class="card-footer">
                        <a href="{{ route('tenant.notices.attachment', $notice->id) }}" class="btn btn-sm btn-subtle-primary">
                            <i class="ri-download-2-line me-1"></i>{{ $notice->attachmentName() }}
                        </a>
                    </div>
                @endif
            </article>

            @if ($notice->expires_at)
                <p class="text-muted fs-13 mt-3 mb-0">
                    <i class="ri-time-line me-1"></i>{{ __('Taken down on :date', ['date' => $school->localTime($notice->expires_at)->format($school->setting('date_format').', '.$school->setting('time_format'))]) }}
                </p>
            @endif
        </div>
    </div>
</div>
