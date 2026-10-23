<?php

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Billing\Money;
use Illuminate\Support\Facades\DB;

use function Livewire\Volt\{computed, layout, title};

layout('layouts.app');
title(__('Dashboard'));

$stats = computed(function () {
    $today = tenant()->today();

    return [
        'openInvoices' => Invoice::query()->open()->count(),
        'outstanding' => (float) Invoice::query()->open()->sum(DB::raw('total_amount - paid_amount')),
        'overdue' => Invoice::query()->where('status', InvoiceStatus::Overdue)->count(),
        'collectedThisMonth' => (float) Payment::query()->valid()
            ->whereDate('payment_date', '>=', $today->copy()->startOfMonth())
            ->whereDate('payment_date', '<=', $today->copy()->endOfMonth())
            ->sum('amount_paid'),
        'collectedToday' => (float) Payment::query()->valid()->whereDate('payment_date', $today)->sum('amount_paid'),
    ];
});

$recent = computed(fn () => auth()->user()->can('fees.view')
    ? Payment::query()->with('invoice.student.user')->latest('id')->limit(6)->get()
    : collect());

?>

@php($format = tenant()->setting('date_format'))

<div>
    <x-page-header :title="__('Dashboard')" :breadcrumbs="[__('Accounting') => null, __('Dashboard') => null]">
        @can('fees.collect')
            <x-slot:actions>
                <a href="{{ route('accounting.collect') }}" wire:navigate class="btn btn-primary"><i class="ri-hand-coin-line me-1"></i>{{ __('Collect fees') }}</a>
            </x-slot:actions>
        @endcan
    </x-page-header>

    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3">
            <x-stat-card :label="__('Collected this month')" :value="Money::school($this->stats['collectedThisMonth'])" icon="ri-hand-coin-line" color="success"
                         :hint="__('Today: :amount', ['amount' => Money::school($this->stats['collectedToday'])])" />
        </div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Outstanding')" :value="Money::school($this->stats['outstanding'])" icon="ri-wallet-3-line" color="warning" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Unpaid invoices')" :value="number_format($this->stats['openInvoices'])" icon="ri-bill-line" color="primary" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Overdue invoices')" :value="number_format($this->stats['overdue'])" icon="ri-alarm-warning-line" color="danger" /></div>
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <x-dashboard.welcome />
            <div class="mt-4">
                <x-notices.latest />
            </div>
        </div>
        @if ($this->recent->isNotEmpty())
            <div class="col-xl-5">
                <div class="card mb-0">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h6 class="card-title mb-0">{{ __('Latest receipts') }}</h6>
                        <a href="{{ route('accounting.payments.index') }}" wire:navigate class="fs-13">{{ __('All payments') }}</a>
                    </div>
                    <ul class="list-group list-group-flush">
                        @foreach ($this->recent as $payment)
                            <li class="list-group-item d-flex justify-content-between gap-2">
                                <div class="min-w-0">
                                    <span class="d-block text-truncate">{{ $payment->invoice?->student?->user?->name }}</span>
                                    <small class="text-muted">{{ $payment->receipt_number }} · {{ $payment->payment_date->format($format) }}</small>
                                </div>
                                <span @class(['fw-medium text-nowrap', 'text-decoration-line-through text-muted' => $payment->isVoided()])>{{ Money::school($payment->amount_paid) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    </div>
</div>
