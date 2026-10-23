<?php

/*
 * Monthly payroll (?month=YYYY-MM): generate drafts, adjust, approve, pay.
 */

use App\Enums\PayrollStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\PayrollRecord;
use App\Services\Payroll\Payroll;
use App\Support\Billing\Money;
use Illuminate\Support\Carbon;

use function Livewire\Volt\{computed, layout, mount, on, state, title, updated, uses};

layout('layouts.app');
title(__('Payroll'));

uses([InteractsWithUi::class]);

state(['month' => ''])->url();

mount(function () {
    $this->authorize('payroll.view');

    if (! Payroll::isMonth($this->month)) {
        $this->month = tenant()->today()->format('Y-m');
    }
});

on(['refresh-tables' => fn () => null, 'payroll-changed' => fn () => null]);

updated(['month' => function () {
    if (! Payroll::isMonth($this->month)) {
        $this->month = tenant()->today()->format('Y-m');
    }
}]);

$summary = computed(function () {
    $records = PayrollRecord::query()->where('month_year', $this->month)->get(['status', 'net_salary']);

    return [
        'count' => $records->count(),
        'net' => (float) $records->sum(fn ($record) => (float) $record->net_salary),
        'draft' => $records->where('status', PayrollStatus::Draft)->count(),
        'approved' => $records->where('status', PayrollStatus::Approved)->count(),
        'paid' => $records->where('status', PayrollStatus::Paid)->count(),
        'pending' => app(Payroll::class)->pendingStaff($this->month)->count(),
    ];
});

$shift = function (int $months) {
    $this->month = Carbon::createFromFormat('!Y-m', Payroll::isMonth($this->month) ? $this->month : tenant()->today()->format('Y-m'))->addMonthsNoOverflow($months)->format('Y-m');
};

$generate = function (Payroll $payroll) {
    $this->authorize('payroll.generate');
    abort_unless(Payroll::isMonth($this->month), 404);

    $created = $payroll->generate($this->month, auth()->user());

    $this->refreshTables();
    $this->toast($created > 0
        ? trans_choice(':count draft created.|:count drafts created.', $created, ['count' => $created])
        : __('Everyone with a salary already has payroll for this month.'), $created > 0 ? 'success' : 'info');
};

?>

@php($summary = $this->summary)

<div>
    <x-page-header :title="__('Payroll')" :breadcrumbs="[__('HR') => route('hr.dashboard'), __('Payroll') => null]">
        <x-slot:actions>
            @if ($summary['approved'] > 0 && auth()->user()->can('payroll.pay'))
                <button type="button" class="btn btn-success" wire:click="$dispatch('open-modal', @js(['component' => 'forms.payroll-payment', 'params' => ['month' => $month]]))">
                    <i class="ri-money-dollar-circle-line me-1"></i>{{ __('Pay approved (:count)', ['count' => $summary['approved']]) }}
                </button>
            @endif
            @can('payroll.generate')
                <button type="button" class="btn btn-primary" wire:click="generate" wire:loading.attr="disabled" wire:target="generate" @disabled($summary['pending'] === 0)>
                    <i class="ri-play-list-add-line me-1"></i>{{ $summary['pending'] > 0 ? __('Generate for :count staff', ['count' => $summary['pending']]) : __('All generated') }}
                </button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
        <button type="button" class="btn btn-light icon-btn" wire:click="shift(-1)" aria-label="{{ __('Previous month') }}"><i class="ri-arrow-left-s-line"></i></button>
        <input type="month" class="form-control w-auto" wire:model.live="month" aria-label="{{ __('Month') }}">
        <button type="button" class="btn btn-light icon-btn" wire:click="shift(1)" aria-label="{{ __('Next month') }}"><i class="ri-arrow-right-s-line"></i></button>
        <span class="ms-2 fw-medium">{{ Payroll::isMonth($month) ? Payroll::monthLabel($month) : '' }}</span>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Net pay')" :value="Money::school($summary['net'])" icon="ri-money-dollar-box-line" color="primary" :hint="trans_choice(':count staff member|:count staff members', $summary['count'], ['count' => $summary['count']])" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Draft')" :value="number_format($summary['draft'])" icon="ri-draft-line" color="secondary" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Approved')" :value="number_format($summary['approved'])" icon="ri-checkbox-circle-line" color="info" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Paid')" :value="number_format($summary['paid'])" icon="ri-hand-coin-line" color="success" /></div>
    </div>

    <p class="text-muted mb-4">{{ __('Drafts take the basic salary less unpaid leave, absences (if the school deducts them) and days before joining. Add allowances or deductions, recalculate after correcting attendance, then approve and pay.') }}</p>

    @if (Payroll::isMonth($month))
        <livewire:components.data-table :table="App\Tables\PayrollTable::class" :params="['month' => $month]" wire:key="payroll-{{ $month }}" />
    @endif
</div>
