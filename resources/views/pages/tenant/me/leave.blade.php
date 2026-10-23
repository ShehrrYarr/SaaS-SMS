<?php

/*
 * My leave (every staff portal): this year's balances and the signed-in
 * staff member's own requests.
 */

use App\Services\Attendance\SchoolCalendar;
use App\Services\Leave\LeaveBalances;
use App\Support\Portal;
use App\Tables\StaffLeaveTable;

use function Livewire\Volt\{computed, layout, mount, on, title};

layout('layouts.app');
title(__('My leave'));

mount(fn () => $this->authorize('leave.apply'));

on(['leave-changed' => fn () => null, 'refresh-tables' => fn () => null]);

$staff = computed(fn () => auth()->user()->staffProfile()->first());

$year = computed(fn () => app(SchoolCalendar::class)->yearFor(tenant()->today()));

$balances = computed(fn () => $this->staff && $this->year ? app(LeaveBalances::class)->summary($this->staff, $this->year) : collect());

?>

@php
    $portal = Portal::current() ?? 'teacher';
    $days = fn ($value) => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
@endphp

<div>
    <x-page-header :title="__('My leave')" :breadcrumbs="[Portal::label($portal) => route(Portal::dashboardRoute($portal)), __('My leave') => null]">
        @if ($this->staff)
            <x-slot:actions>
                <button type="button" class="btn btn-primary" wire:click="$dispatch('open-modal', @js(['component' => 'forms.staff-leave', 'params' => ['as' => 'self']]))">
                    <i class="ri-add-line me-1"></i>{{ __('Apply for leave') }}
                </button>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if (! $this->staff)
        <div class="card mb-0">
            <x-empty-state icon="ri-user-unfollow-line" :title="__('No staff record')" :message="__('Your account isn\'t linked to a staff record, so leave can\'t be requested. Ask HR to add you.')" />
        </div>
    @else
        @if ($this->balances->isNotEmpty())
            <div class="row g-3 mb-4">
                @foreach ($this->balances as $row)
                    <div class="col-sm-6 col-xl-3" wire:key="balance-{{ $row['type']->id }}">
                        <div class="card h-100 mb-0">
                            <div class="card-body">
                                <p class="text-muted mb-1">{{ $row['type']->name }}@unless ($row['type']->is_paid) <span class="badge bg-warning-subtle text-warning">{{ __('Unpaid') }}</span>@endunless</p>
                                @if ($row['allocated'] === null)
                                    <h4 class="mb-0">{{ $days($row['used']) }} <small class="text-muted fs-13 fw-normal">{{ __('taken') }}</small></h4>
                                    <small class="text-muted">{{ __('No yearly limit') }}</small>
                                @else
                                    <h4 class="mb-0">{{ $days(max($row['remaining'], 0)) }} <small class="text-muted fs-13 fw-normal">{{ __('of :days left', ['days' => $days($row['allocated'])]) }}</small></h4>
                                    <small class="text-muted">{{ __(':used taken', ['used' => $days($row['used'])]) }}@if ($row['pending'] > 0) · {{ __(':pending awaiting approval', ['pending' => $days($row['pending'])]) }}@endif</small>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <livewire:components.data-table :table="StaffLeaveTable::class" :params="['scope' => 'self']" />
    @endif
</div>
