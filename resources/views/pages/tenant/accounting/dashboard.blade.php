<?php

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

use function Livewire\Volt\{computed, layout, protect, title};

layout('layouts.app');
title(__('Dashboard'));

$stats = computed(function () {
    $open = [InvoiceStatus::Unpaid, InvoiceStatus::Partial, InvoiceStatus::Overdue];

    return [
        'openInvoices' => Invoice::query()->whereIn('status', $open)->count(),
        'outstanding' => (float) Invoice::query()->whereIn('status', $open)->sum(DB::raw('total_amount - paid_amount')),
        'overdue' => Invoice::query()->where('status', InvoiceStatus::Overdue)->count(),
        'collectedThisMonth' => (float) Payment::query()->valid()
            ->whereBetween('payment_date', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount_paid'),
    ];
});

$money = protect(fn (float $amount) => tenant()->setting('currency_symbol').number_format($amount, 2));

?>

<div>
    <x-page-header :title="__('Dashboard')" :breadcrumbs="[__('Accounting') => null, __('Dashboard') => null]" />

    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Collected this month')" :value="$this->money($this->stats['collectedThisMonth'])" icon="ri-hand-coin-line" color="success" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Outstanding')" :value="$this->money($this->stats['outstanding'])" icon="ri-wallet-3-line" color="warning" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Open invoices')" :value="number_format($this->stats['openInvoices'])" icon="ri-bill-line" color="primary" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Overdue invoices')" :value="number_format($this->stats['overdue'])" icon="ri-alarm-warning-line" color="danger" /></div>
    </div>

    <x-dashboard.welcome />
</div>
