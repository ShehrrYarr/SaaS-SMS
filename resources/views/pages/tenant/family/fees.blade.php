<?php

/*
 * Student and parent portals: fees — what is owed, every invoice, and the
 * payments made, with invoices and receipts to view or download.
 */

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Portals\StudentOverview;
use App\Support\Billing\Money;
use App\Support\Portal;
use App\Support\SelfService;
use Illuminate\Database\Eloquent\Builder;

use function Livewire\Volt\{computed, layout, state, title, updated, usesPagination, with};

layout('layouts.app');
title(__('Fees'));

usesPagination(theme: 'bootstrap');

state(['tab' => 'invoices'])->url();

$student = computed(fn () => SelfService::student());

$summary = computed(fn () => $this->student ? app(StudentOverview::class)->fees($this->student) : null);

updated(['tab' => fn () => $this->resetPage()]);

with(function () {
    if (! $this->student) {
        return ['invoices' => null, 'payments' => null];
    }

    $id = $this->student->id;

    return $this->tab === 'payments'
        ? ['invoices' => null, 'payments' => Payment::query()->valid()->with('invoice')
            ->whereHas('invoice', fn (Builder $query) => $query->where('student_profile_id', $id))
            ->latest('payment_date')->latest('id')->paginate(10)]
        : ['payments' => null, 'invoices' => Invoice::query()->with(['items', 'payments' => fn ($query) => $query->valid()])
            ->where('student_profile_id', $id)
            ->where('status', '!=', InvoiceStatus::Cancelled)
            ->latest('issue_date')->latest('id')->paginate(10)];
});

?>

@php
    $portal = Portal::current();
    $format = tenant()->setting('date_format');
    $today = tenant()->today();
@endphp

<div>
    <x-page-header :title="__('Fees')" :breadcrumbs="SelfService::breadcrumbs([__('Fees') => null])" />

    @if (! $this->student)
        <x-family.missing />
    @else
        @php($summary = $this->summary)
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <x-stat-card :label="__('Outstanding')" :value="Money::school($summary['outstanding'])" icon="ri-wallet-3-line"
                             :color="$summary['outstanding'] > 0 ? 'warning' : 'success'"
                             :hint="trans_choice(':count open invoice|:count open invoices', $summary['open'], ['count' => $summary['open']])" />
            </div>
            <div class="col-md-4">
                <x-stat-card :label="__('Overdue')" :value="Money::school($summary['overdue'])" icon="ri-alarm-warning-line"
                             :color="$summary['overdue'] > 0 ? 'danger' : 'secondary'"
                             :hint="$summary['next'] ? __('Next due :date', ['date' => $summary['next']->due_date->format($format)]) : __('Nothing due')" />
            </div>
            <div class="col-md-4">
                <x-stat-card :label="__('Paid this year')" :value="Money::school($summary['paid_this_year'])" icon="ri-hand-coin-line" color="success" />
            </div>
        </div>

        <p class="text-muted fs-13 mb-3"><i class="ri-information-line me-1"></i>{{ __('Fees are paid at the school office; each payment gets a receipt you can download here.') }}</p>

        <ul class="nav nav-tabs nav-tabs-bordered mb-4" role="tablist">
            @foreach (['invoices' => __('Invoices'), 'payments' => __('Payments & receipts')] as $key => $label)
                <li class="nav-item" role="presentation">
                    <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                            @class(['nav-link', 'active' => $tab === $key])>{{ $label }}</button>
                </li>
            @endforeach
        </ul>

        @if ($tab === 'payments')
            <div class="card mb-0">
                @if ($payments->isEmpty())
                    <x-empty-state icon="ri-secure-payment-line" :title="__('No payments yet')" />
                @else
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="bg-light bg-opacity-30">
                                <tr>
                                    <th class="ps-4">{{ __('Receipt') }}</th>
                                    <th>{{ __('Date') }}</th>
                                    <th class="d-none d-md-table-cell">{{ __('Method') }}</th>
                                    <th class="text-end">{{ __('Amount') }}</th>
                                    <th class="pe-4 text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($payments as $payment)
                                    <tr wire:key="payment-{{ $payment->id }}">
                                        <td class="ps-4">
                                            <span class="fw-medium">{{ $payment->receipt_number }}</span>
                                            <small class="d-block text-muted">{{ $payment->invoice?->invoice_number }}</small>
                                        </td>
                                        <td class="text-nowrap">{{ $payment->payment_date->format($format) }}</td>
                                        <td class="d-none d-md-table-cell">{{ $payment->payment_method->label() }}</td>
                                        <td class="text-end fw-semibold">{{ Money::school($payment->amount_paid) }}</td>
                                        <td class="pe-4 text-end text-nowrap">
                                            <a href="{{ route($portal.'.receipts.document', $payment->id) }}" target="_blank" class="btn btn-sm btn-light" title="{{ __('View receipt') }}"><i class="ri-printer-line"></i></a>
                                            <a href="{{ route($portal.'.receipts.pdf', $payment->id) }}" class="btn btn-sm btn-light" title="{{ __('Download PDF') }}"><i class="ri-download-2-line"></i></a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($payments->hasPages())
                        <div class="card-footer">{{ $payments->links() }}</div>
                    @endif
                @endif
            </div>
        @else
            @if ($invoices->isEmpty())
                <div class="card mb-0"><x-empty-state icon="ri-bill-line" :title="__('No invoices yet')" /></div>
            @else
                @foreach ($invoices as $invoice)
                    @php($late = $invoice->isOpen() && $invoice->due_date->lt($today))
                    <div class="card mb-3" wire:key="invoice-{{ $invoice->id }}">
                        <div class="card-header d-flex flex-wrap align-items-center gap-2">
                            <div class="flex-grow-1 min-w-0">
                                <span class="fw-semibold">{{ $invoice->invoice_number }}</span>
                                <span class="text-muted ms-1">{{ $invoice->periodLabel() }}</span>
                                <small @class(['d-block', 'text-danger' => $late, 'text-muted' => ! $late])>{{ __('Due :date', ['date' => $invoice->due_date->format($format)]) }}</small>
                            </div>
                            <span class="badge bg-{{ $invoice->status->color() }}-subtle text-{{ $invoice->status->color() }}">{{ $invoice->status->label() }}</span>
                            <a href="{{ route($portal.'.invoices.document', $invoice->id) }}" target="_blank" class="btn btn-sm btn-light" title="{{ __('View invoice') }}"><i class="ri-printer-line"></i></a>
                            <a href="{{ route($portal.'.invoices.pdf', $invoice->id) }}" class="btn btn-sm btn-light" title="{{ __('Download PDF') }}"><i class="ri-download-2-line"></i></a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0 fs-13">
                                <tbody>
                                    @foreach ($invoice->items as $item)
                                        <tr>
                                            <td class="ps-4">{{ $item->description }}
                                                @if ((float) $item->discount_amount > 0)<small class="text-success ms-1">{{ __(':amount off', ['amount' => Money::school($item->discount_amount)]) }}</small>@endif
                                            </td>
                                            <td class="pe-4 text-end">{{ Money::school($item->net_amount) }}</td>
                                        </tr>
                                    @endforeach
                                    @if ((float) $invoice->late_fee_amount > 0)
                                        <tr><td class="ps-4 text-danger">{{ __('Late fee') }}</td><td class="pe-4 text-end text-danger">{{ Money::school($invoice->late_fee_amount) }}</td></tr>
                                    @endif
                                </tbody>
                                <tfoot class="fw-semibold">
                                    <tr><td class="ps-4">{{ __('Total') }}</td><td class="pe-4 text-end">{{ Money::school($invoice->total_amount) }}</td></tr>
                                    @if ((float) $invoice->paid_amount > 0)
                                        <tr class="text-success fw-normal"><td class="ps-4">{{ __('Paid') }}</td><td class="pe-4 text-end">-{{ Money::school($invoice->paid_amount) }}</td></tr>
                                    @endif
                                    @if ($invoice->isOpen())
                                        <tr><td class="ps-4">{{ __('Balance') }}</td><td @class(['pe-4 text-end', 'text-danger' => $late])>{{ Money::school($invoice->balanceAmount()) }}</td></tr>
                                    @endif
                                </tfoot>
                            </table>
                        </div>
                        @if ($invoice->payments->isNotEmpty())
                            <div class="card-footer d-flex flex-wrap gap-2 fs-13">
                                <span class="text-muted">{{ __('Receipts:') }}</span>
                                @foreach ($invoice->payments as $payment)
                                    <a href="{{ route($portal.'.receipts.document', $payment->id) }}" target="_blank" class="link-primary" wire:key="receipt-{{ $payment->id }}">
                                        {{ $payment->receipt_number }} ({{ Money::school($payment->amount_paid) }})
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
                @if ($invoices->hasPages())
                    <div class="mt-3">{{ $invoices->links() }}</div>
                @endif
            @endif
        @endif
    @endif
</div>
