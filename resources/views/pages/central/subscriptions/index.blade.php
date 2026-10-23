<?php

use App\Enums\SchoolStatus;
use App\Enums\SubscriptionState;
use App\Models\School;
use App\Models\SubscriptionPayment;
use App\Support\Billing\Money;
use App\Tables\Central\SubscriptionPaymentsTable;

use function Livewire\Volt\{computed, layout, on, title};

layout('layouts.super-admin');
title(__('Subscriptions'));

$stats = computed(fn () => [
    'month' => SubscriptionPayment::query()->whereBetween('paid_on', [today()->startOfMonth(), today()->endOfMonth()])->sum('amount'),
    'year' => SubscriptionPayment::query()->whereBetween('paid_on', [today()->startOfYear(), today()->endOfYear()])->sum('amount'),
    'grace' => School::query()->where('status', SchoolStatus::Active)->whereSubscriptionState(SubscriptionState::Grace)->count(),
    'expiring' => School::query()->where('status', SchoolStatus::Active)->expiringWithin(30)->count(),
]);

// Active schools that need a payment soon, or are already in their grace period.
$attention = computed(fn () => School::query()
    ->with('plan')
    ->where('status', SchoolStatus::Active)
    ->where(fn ($query) => $query->expiringWithin(30)->orWhere(fn ($query) => $query->whereSubscriptionState(SubscriptionState::Grace)))
    ->get()
    ->sortBy(fn (School $school) => $school->accessEndsAt())
    ->take(10));

on(['refresh-tables' => function () {
    unset($this->stats, $this->attention);
}]);

?>

<div>
    <x-page-header :title="__('Subscriptions')" :breadcrumbs="[__('Super Admin') => route('central.dashboard'), __('Subscriptions') => null]">
        <x-slot:actions>
            <button type="button" class="btn btn-primary" wire:click="$dispatch('open-modal', { component: 'forms.central.subscription-payment', size: 'lg' })">
                <i class="ri-add-line me-1"></i>{{ __('Record payment') }}
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Collected this month')" :value="Money::platform($this->stats['month'])" icon="ri-money-dollar-circle-line" color="success" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Collected this year')" :value="Money::platform($this->stats['year'])" icon="ri-funds-line" color="primary" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Ending in 30 days')" :value="number_format($this->stats['expiring'])" icon="ri-calendar-event-line" color="info" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('In grace period')" :value="number_format($this->stats['grace'])" icon="ri-time-line" color="warning"
                                                    :hint="__('Suspended after :days days', ['days' => config('plans.subscriptions.grace_days')])" /></div>
    </div>

    @if ($this->attention->isNotEmpty())
        <div class="card mb-4">
            <div class="card-header"><h5 class="card-title mb-0">{{ __('Needs a payment soon') }}</h5></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle table-nowrap mb-0">
                    <thead class="bg-light bg-opacity-30">
                        <tr>
                            <th scope="col">{{ __('School') }}</th>
                            <th scope="col" class="d-none d-md-table-cell">{{ __('Plan') }}</th>
                            <th scope="col">{{ __('Subscription') }}</th>
                            <th scope="col" class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->attention as $school)
                            <tr wire:key="attention-{{ $school->id }}">
                                <td><a href="{{ route('central.schools.show', $school) }}" wire:navigate class="fw-medium">{{ $school->name }}</a></td>
                                <td class="d-none d-md-table-cell">{{ $school->plan?->name ?? '—' }}</td>
                                <td>@include('tables.central.school-subscription', ['row' => $school])</td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-subtle-primary"
                                            wire:click="$dispatch('open-modal', { component: 'forms.central.subscription-payment', params: { school: {{ $school->id }} }, size: 'lg' })">
                                        {{ __('Record payment') }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <h5 class="mb-3">{{ __('Payment history') }}</h5>
    <livewire:components.data-table :table="SubscriptionPaymentsTable::class" />
</div>
