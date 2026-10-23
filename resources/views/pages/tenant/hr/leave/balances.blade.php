<?php

/*
 * Staff leave balances for an academic year (?year=). Missing balance rows
 * are created on opening, so new staff and new leave types show up.
 */

use App\Models\AcademicYear;
use App\Services\Leave\LeaveBalances;
use App\Tables\LeaveBalancesTable;

use function Livewire\Volt\{computed, layout, mount, protect, state, title, updated};

layout('layouts.app');
title(__('Leave balances'));

state(['year' => ''])->url();

mount(function () {
    $this->authorize('leave.view');

    if (! $this->years->has((int) $this->year)) {
        $this->year = (string) (AcademicYear::query()->current()->value('id') ?? $this->years->keys()->first() ?? '');
    }

    $this->prepare();
});

$years = computed(fn () => AcademicYear::query()->orderByDesc('start_date')->pluck('name', 'id'));

$prepare = protect(function () {
    if ($year = AcademicYear::query()->find((int) $this->year)) {
        app(LeaveBalances::class)->ensureAll($year);
    }
});

updated(['year' => fn () => $this->prepare()]);

?>

<div>
    <x-page-header :title="__('Leave balances')" :breadcrumbs="[__('HR') => route('hr.dashboard'), __('Leave') => null, __('Balances') => null]" />

    <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
        <p class="text-muted mb-0 flex-grow-1">{{ __('Days each staff member may take of leave types with a yearly limit, and how many they have taken. Leave counts against the year it starts in.') }}</p>
        @if ($this->years->isNotEmpty())
            <select class="form-select w-auto" wire:model.live="year" aria-label="{{ __('Academic year') }}">
                @foreach ($this->years as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        @endif
    </div>

    @if ($year)
        <livewire:components.data-table :table="LeaveBalancesTable::class" :params="['year' => (int) $year]" wire:key="balances-{{ $year }}" />
    @else
        <div class="card mb-0"><x-empty-state icon="ri-calendar-2-line" :title="__('No academic year')" :message="__('Set up an academic year to track leave balances.')" /></div>
    @endif
</div>
