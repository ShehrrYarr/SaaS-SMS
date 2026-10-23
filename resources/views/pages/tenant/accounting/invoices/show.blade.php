<?php

/*
 * One invoice: what was billed (with each reduction), payments received,
 * and the actions on it.
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Fees\Invoices;
use App\Support\Billing\Money;
use App\Tables\ActionFailed;

use function Livewire\Volt\{computed, layout, mount, on, state, title, uses};

layout('layouts.app');
title(fn () => __('Invoice :number', ['number' => $this->record->invoice_number]));

uses([InteractsWithUi::class]);

state(['invoice' => null])->locked();
state(['lastPayment' => null]);

mount(function (int $invoice) {
    $this->authorize('invoices.view');
    $this->invoice = Invoice::query()->findOrFail($invoice)->id;
});

on([
    'invoice-updated' => fn () => null,
    'payment-recorded' => fn (int $payment) => $this->lastPayment = $payment,
]);

$record = computed(fn () => Invoice::query()
    ->with([
        'student.user', 'student.schoolClass', 'student.section', 'student.parents.user',
        'items', 'creator', 'batch', 'academicYear',
        'payments' => fn ($query) => $query->with(['receivedBy', 'voidedBy'])->orderBy('payment_date')->orderBy('id'),
    ])
    ->findOrFail($this->invoice));

$receipt = computed(fn () => $this->lastPayment ? Payment::query()->where('invoice_id', $this->invoice)->find($this->lastPayment) : null);

$waiveLateFee = function (Invoices $invoices) {
    $this->authorize('invoices.edit');

    try {
        $invoices->waiveLateFee($this->record, auth()->user());
    } catch (ActionFailed $e) {
        $this->toast($e->getMessage(), 'error');

        return;
    }

    unset($this->record);
    $this->toast(__('Late fee waived.'));
};

?>

@php
    $invoice = $this->record;
    $student = $invoice->student;
    $school = tenant();
    $format = $school->setting('date_format');
    $user = auth()->user();
    $guardian = $student?->parents->sortByDesc(fn ($parent) => (bool) $parent->pivot->is_primary)->first();
@endphp

<div>
    <x-page-header :title="__('Invoice :number', ['number' => $invoice->invoice_number])"
                   :breadcrumbs="[__('Accounting') => route('accounting.dashboard'), __('Invoices') => route('accounting.invoices.index'), $invoice->invoice_number => null]">
        <x-slot:actions>
            @if ($invoice->isOpen() && $user->can('fees.collect'))
                <button type="button" class="btn btn-success" wire:click="$dispatch('open-modal', @js(['component' => 'forms.payment', 'params' => ['invoice' => $invoice->id]]))">
                    <i class="ri-hand-coin-line me-1"></i>{{ __('Receive payment') }}
                </button>
            @endif
            @unless ($invoice->isCancelled())
                <a href="{{ route('accounting.invoices.document', $invoice->id) }}" target="_blank" rel="noopener" class="btn btn-light"><i class="ri-printer-line me-1"></i>{{ __('Print') }}</a>
            @endunless
            @if (! $invoice->isCancelled() && $user->canAny(['invoices.edit', 'invoices.cancel']))
                <div class="dropdown">
                    <button type="button" class="btn btn-light icon-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('More actions') }}"><i class="ri-more-2-fill"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        @can('invoices.edit')
                            <li><button type="button" class="dropdown-item" wire:click="$dispatch('open-modal', @js(['component' => 'forms.invoice-details', 'params' => ['invoice' => $invoice->id]]))"><i class="ri-pencil-line me-2"></i>{{ __('Edit due date & notes') }}</button></li>
                            @if ($invoice->isOpen() && (float) $invoice->late_fee_amount > 0)
                                <li>
                                    <button type="button" class="dropdown-item"
                                            x-on:click="SSMS.confirm(@js(['title' => __('Waive the late fee?'), 'text' => __(':amount is taken off and won\'t be charged again on this invoice.', ['amount' => Money::school($invoice->late_fee_amount)]), 'confirmText' => __('Waive'), 'cancelText' => __('Cancel')])).then(ok => ok && $wire.waiveLateFee())">
                                        <i class="ri-eraser-line me-2"></i>{{ __('Waive late fee') }}
                                    </button>
                                </li>
                            @endif
                        @endcan
                        @if ($user->can('invoices.cancel') && (float) $invoice->paid_amount <= 0)
                            <li><button type="button" class="dropdown-item text-danger" wire:click="$dispatch('open-modal', @js(['component' => 'forms.cancel-invoice', 'params' => ['invoice' => $invoice->id]]))"><i class="ri-close-circle-line me-2"></i>{{ __('Cancel invoice') }}</button></li>
                        @endif
                    </ul>
                </div>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($this->receipt)
        <div class="alert alert-success d-flex flex-wrap align-items-center gap-2">
            <i class="ri-checkbox-circle-line fs-5 lh-1"></i>
            <span class="flex-grow-1">{{ __('Received :amount. Receipt :number.', ['amount' => Money::school($this->receipt->amount_paid), 'number' => $this->receipt->receipt_number]) }}</span>
            <a href="{{ route('accounting.receipts.document', $this->receipt->id) }}" target="_blank" rel="noopener" class="btn btn-sm btn-success"><i class="ri-printer-line me-1"></i>{{ __('Print receipt') }}</a>
        </div>
    @endif

    @if ($invoice->isCancelled())
        <div class="alert alert-secondary d-flex gap-2">
            <i class="ri-close-circle-line fs-5 lh-1"></i>
            <div>{{ __('Cancelled on :date.', ['date' => $school->localTime($invoice->cancelled_at ?? $invoice->updated_at)->format($format)]) }} {{ $invoice->cancel_reason }}</div>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card mb-0">
                <div class="card-body">
                    <div class="row g-4 mb-4">
                        <div class="col-sm-6">
                            <h6 class="text-muted text-uppercase fs-12 mb-2">{{ __('Bill to') }}</h6>
                            @if ($student)
                                @if ($user->canAccessPortal('admin') && $user->can('students.view'))
                                    <a href="{{ route('admin.students.show', $student->id) }}" wire:navigate class="fw-semibold text-body d-block">{{ $student->user?->name }}</a>
                                @else
                                    <span class="fw-semibold d-block">{{ $student->user?->name }}</span>
                                @endif
                                <div class="text-muted fs-13">{{ collect([$student->admission_number, $student->placementLabel()])->filter()->implode(' · ') }}</div>
                                @if ($guardian)
                                    <div class="text-muted fs-13">{{ $guardian->user?->name }}{{ $guardian->user?->phone ? ' · '.$guardian->user->phone : '' }}</div>
                                @endif
                            @endif
                        </div>
                        <div class="col-sm-6">
                            <dl class="row mb-0 fs-13">
                                <dt class="col-5 text-muted fw-normal">{{ __('Status') }}</dt>
                                <dd class="col-7 mb-1"><span class="badge bg-{{ $invoice->status->color() }}-subtle text-{{ $invoice->status->color() }}">{{ $invoice->status->label() }}</span></dd>
                                @if ($invoice->billing_period)
                                    <dt class="col-5 text-muted fw-normal">{{ __('Month') }}</dt><dd class="col-7 mb-1">{{ $invoice->periodLabel() }}</dd>
                                @endif
                                <dt class="col-5 text-muted fw-normal">{{ __('Issued') }}</dt><dd class="col-7 mb-1">{{ $invoice->issue_date->format($format) }}</dd>
                                <dt class="col-5 text-muted fw-normal">{{ __('Due') }}</dt><dd class="col-7 mb-1">{{ $invoice->due_date->format($format) }}</dd>
                                @if ($invoice->batch)
                                    <dt class="col-5 text-muted fw-normal">{{ __('Bulk run') }}</dt><dd class="col-7 mb-0">{{ $invoice->batch->title }}</dd>
                                @endif
                            </dl>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>{{ __('Description') }}</th><th class="text-end">{{ __('Amount') }}</th><th class="text-end">{{ __('Reduction') }}</th><th class="text-end">{{ __('Net') }}</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($invoice->items as $item)
                                    <tr>
                                        <td>
                                            {{ $item->description }}
                                            @foreach ($item->discount_breakdown ?? [] as $reduction)
                                                <small class="d-block text-muted">{{ $reduction['label'] }} ({{ $reduction['type'] === 'percentage' ? Money::input($reduction['value']).'%' : Money::school($reduction['value']) }}): -{{ Money::school($reduction['amount']) }}</small>
                                            @endforeach
                                        </td>
                                        <td class="text-end">{{ Money::school($item->amount) }}</td>
                                        <td class="text-end">{{ (float) $item->discount_amount > 0 ? '-'.Money::school($item->discount_amount) : '—' }}</td>
                                        <td class="text-end">{{ Money::school($item->net_amount) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr><td colspan="3" class="text-end text-muted">{{ __('Subtotal') }}</td><td class="text-end">{{ Money::school($invoice->subtotal) }}</td></tr>
                                @if ((float) $invoice->discount_amount > 0)
                                    <tr><td colspan="3" class="text-end text-muted">{{ __('Reductions') }}</td><td class="text-end">-{{ Money::school($invoice->discount_amount) }}</td></tr>
                                @endif
                                @if ((float) $invoice->late_fee_amount > 0 || $invoice->late_fee_waived_at)
                                    <tr>
                                        <td colspan="3" class="text-end text-muted">{{ __('Late fee') }}@if ($invoice->late_fee_waived_at) <span class="badge bg-secondary-subtle text-secondary ms-1">{{ __('waived') }}</span>@endif</td>
                                        <td class="text-end text-danger">{{ Money::school($invoice->late_fee_amount) }}</td>
                                    </tr>
                                @endif
                                <tr class="fw-semibold"><td colspan="3" class="text-end">{{ __('Total') }}</td><td class="text-end">{{ Money::school($invoice->total_amount) }}</td></tr>
                            </tfoot>
                        </table>
                    </div>

                    @if ($invoice->notes)
                        <div class="mt-3 p-3 bg-light rounded text-pre-line fs-13">{{ $invoice->notes }}</div>
                    @endif
                </div>
                <div class="card-footer text-muted fs-12">
                    {{ __('Raised by :name on :date', ['name' => $invoice->creator?->name ?? __('the system'), 'date' => $school->localTime($invoice->created_at)->format($format)]) }}
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-6 text-muted fw-normal">{{ __('Total') }}</dt><dd class="col-6 text-end mb-2">{{ Money::school($invoice->total_amount) }}</dd>
                        <dt class="col-6 text-muted fw-normal">{{ __('Paid') }}</dt><dd class="col-6 text-end mb-2 text-success">{{ Money::school($invoice->paid_amount) }}</dd>
                        <dt class="col-6">{{ __('Balance') }}</dt>
                        <dd class="col-6 text-end mb-0 fs-5 fw-semibold {{ $invoice->balanceAmount() > 0 && ! $invoice->isCancelled() ? 'text-danger' : '' }}">{{ Money::school($invoice->isCancelled() ? 0 : $invoice->balanceAmount()) }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">{{ __('Payments') }}</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($invoice->payments as $payment)
                        <li @class(['list-group-item', 'opacity-75' => $payment->isVoided()])>
                            <div class="d-flex justify-content-between gap-2">
                                <div class="min-w-0">
                                    <a href="{{ route('accounting.receipts.document', $payment->id) }}" target="_blank" rel="noopener" class="fw-medium">{{ $payment->receipt_number }}</a>
                                    @if ($payment->isVoided())<span class="badge bg-danger-subtle text-danger ms-1">{{ __('Void') }}</span>@endif
                                    <small class="d-block text-muted">{{ $payment->payment_date->format($format) }} · {{ $payment->payment_method->label() }}{{ $payment->transaction_id ? ' · '.$payment->transaction_id : '' }}</small>
                                    <small class="d-block text-muted">{{ __('By :name', ['name' => $payment->receivedBy?->name ?? '—']) }}</small>
                                    @if ($payment->isVoided())
                                        <small class="d-block text-danger">{{ __('Voided by :name: :reason', ['name' => $payment->voidedBy?->name ?? '—', 'reason' => $payment->void_reason]) }}</small>
                                    @endif
                                </div>
                                <div class="text-end">
                                    <span @class(['fw-semibold', 'text-decoration-line-through' => $payment->isVoided()])>{{ Money::school($payment->amount_paid) }}</span>
                                    @if (! $payment->isVoided() && $user->can('fees.void'))
                                        <button type="button" class="btn btn-link btn-sm text-danger p-0 d-block ms-auto"
                                                wire:click="$dispatch('open-modal', @js(['component' => 'forms.void-payment', 'params' => ['payment' => $payment->id]]))">{{ __('Void') }}</button>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @empty
                        <li class="list-group-item text-muted fs-13">{{ __('No payments yet.') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
