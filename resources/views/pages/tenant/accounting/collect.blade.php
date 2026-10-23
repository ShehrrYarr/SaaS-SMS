<?php

/*
 * Fee collection counter: find a student, see what they owe, receive
 * payments and print receipts. ?for={student id} opens a student directly.
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Livewire\Concerns\PicksStudent;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Services\Fees\LateFees;
use App\Support\Billing\Money;

use function Livewire\Volt\{computed, layout, mount, on, protect, state, title, uses};

layout('layouts.app');
title(__('Fee collection'));

uses([InteractsWithUi::class, PicksStudent::class]);

state(['for' => null])->url();
state(['lastPayment' => null]);

mount(function () {
    $this->authorize('fees.collect');

    $student = $this->for ? StudentProfile::query()->find((int) $this->for) : null;
    $student ? $this->setPickedStudent($student) : $this->for = null;
});

on([
    'payment-recorded' => fn (int $payment) => $this->lastPayment = $payment,
    'invoice-updated' => fn () => null,
]);

$studentPicked = protect(function (StudentProfile $student) {
    $this->for = $student->id;
    $this->lastPayment = null;
});

$clear = function () {
    $this->clearStudent();
    $this->for = null;
    $this->lastPayment = null;
};

$profile = computed(fn () => $this->student_id === ''
    ? null
    : StudentProfile::query()->with(['user', 'schoolClass', 'section', 'parents.user'])->find((int) $this->student_id));

// Open invoices, oldest first, with today's late fees charged.
$open = computed(function () {
    if (! $this->profile) {
        return collect();
    }

    $invoices = Invoice::query()->open()->where('student_profile_id', $this->profile->id)->orderBy('due_date')->orderBy('id')->get();
    $lateFees = app(LateFees::class);
    $invoices->each(fn (Invoice $invoice) => $lateFees->apply($invoice));

    return $invoices;
});

$recent = computed(fn () => $this->profile
    ? Payment::query()->with('invoice')->whereHas('invoice', fn ($query) => $query->where('student_profile_id', $this->profile->id))->latest('payment_date')->latest('id')->limit(8)->get()
    : collect());

$receipt = computed(fn () => $this->lastPayment ? Payment::query()->find($this->lastPayment) : null);

?>

@php
    $student = $this->profile;
    $format = tenant()->setting('date_format');
    $today = tenant()->today();
    $user = auth()->user();
@endphp

<div>
    <x-page-header :title="__('Fee collection')" :breadcrumbs="[__('Accounting') => route('accounting.dashboard'), __('Fees') => null, __('Fee collection') => null]" />

    <div class="card">
        <div class="card-body">
            <div class="row align-items-end g-3">
                <div class="col-lg-8">
                    <x-form.student-picker :results="$this->studentResults" :selected="$studentLabel" :picked="$student_id" :search="$studentSearch" :required="false" wrapper="mb-0" />
                </div>
                @if ($student)
                    <div class="col-lg-4 text-lg-end">
                        @can('invoices.create')
                            <button type="button" class="btn btn-light" wire:click="$dispatch('open-modal', @js(['component' => 'forms.invoice', 'params' => ['student' => $student->id], 'size' => 'lg']))">
                                <i class="ri-add-line me-1"></i>{{ __('New invoice') }}
                            </button>
                        @endcan
                        <button type="button" class="btn btn-light" wire:click="clear">{{ __('Next student') }}</button>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if ($this->receipt)
        <div class="alert alert-success d-flex flex-wrap align-items-center gap-2">
            <i class="ri-checkbox-circle-line fs-5 lh-1"></i>
            <span class="flex-grow-1">{{ __('Received :amount. Receipt :number.', ['amount' => Money::school($this->receipt->amount_paid), 'number' => $this->receipt->receipt_number]) }}</span>
            <a href="{{ route('accounting.receipts.document', $this->receipt->id) }}" target="_blank" rel="noopener" class="btn btn-sm btn-success"><i class="ri-printer-line me-1"></i>{{ __('Print receipt') }}</a>
        </div>
    @endif

    @if ($student)
        @php($outstanding = $this->open->sum(fn ($invoice) => $invoice->balanceAmount()))
        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card mb-0">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h6 class="card-title mb-0">{{ __('Unpaid invoices') }}</h6>
                        <span class="text-muted">{{ __('Owed') }}: <span class="fw-semibold {{ $outstanding > 0 ? 'text-danger' : 'text-success' }}">{{ Money::school($outstanding) }}</span></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>{{ __('Invoice') }}</th><th>{{ __('Due') }}</th><th class="text-end">{{ __('Total') }}</th><th class="text-end">{{ __('Balance') }}</th><th></th></tr>
                            </thead>
                            <tbody>
                                @forelse ($this->open as $invoice)
                                    <tr wire:key="open-{{ $invoice->id }}">
                                        <td>
                                            <a href="{{ route('accounting.invoices.show', $invoice->id) }}" wire:navigate class="fw-medium">{{ $invoice->invoice_number }}</a>
                                            <small class="d-block text-muted">{{ $invoice->periodLabel() ?? $invoice->issue_date->format($format) }}</small>
                                        </td>
                                        <td>
                                            <span @class(['text-danger' => $invoice->due_date->lt($today)])>{{ $invoice->due_date->format($format) }}</span>
                                            @if ((float) $invoice->late_fee_amount > 0)
                                                <small class="d-block text-danger">{{ __('Late fee :amount', ['amount' => Money::school($invoice->late_fee_amount)]) }}</small>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ Money::school($invoice->total_amount) }}</td>
                                        <td class="text-end fw-semibold">{{ Money::school($invoice->balanceAmount()) }}</td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-success" wire:click="$dispatch('open-modal', @js(['component' => 'forms.payment', 'params' => ['invoice' => $invoice->id]]))">
                                                {{ __('Receive') }}
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-4"><i class="ri-checkbox-circle-line text-success me-1"></i>{{ __('Nothing is owed.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="mb-1">{{ $student->user?->name }}</h6>
                        <p class="text-muted fs-13 mb-2">{{ collect([$student->admission_number, $student->placementLabel()])->filter()->implode(' · ') }}</p>
                        @unless ($student->isActive())
                            <span class="badge bg-secondary-subtle text-secondary mb-2">{{ $student->status->label() }}</span>
                        @endunless
                        @foreach ($student->parents as $parent)
                            <div class="fs-13"><i class="ri-parent-line text-muted me-1"></i>{{ $parent->user?->name }}{{ $parent->user?->phone ? ' · '.$parent->user->phone : '' }}</div>
                        @endforeach
                    </div>
                </div>

                <div class="card mb-0">
                    <div class="card-header"><h6 class="card-title mb-0">{{ __('Recent receipts') }}</h6></div>
                    <ul class="list-group list-group-flush">
                        @forelse ($this->recent as $payment)
                            <li class="list-group-item d-flex justify-content-between gap-2">
                                <div class="min-w-0">
                                    <a href="{{ route('accounting.receipts.document', $payment->id) }}" target="_blank" rel="noopener" class="fw-medium">{{ $payment->receipt_number }}</a>
                                    @if ($payment->isVoided())<span class="badge bg-danger-subtle text-danger ms-1">{{ __('Void') }}</span>@endif
                                    <small class="d-block text-muted">{{ $payment->payment_date->format($format) }} · {{ $payment->invoice?->invoice_number }}</small>
                                </div>
                                <span @class(['fw-medium', 'text-decoration-line-through text-muted' => $payment->isVoided()])>{{ Money::school($payment->amount_paid) }}</span>
                            </li>
                        @empty
                            <li class="list-group-item text-muted fs-13">{{ __('No payments yet.') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    @else
        <div class="card mb-0">
            <x-empty-state icon="ri-hand-coin-line" :title="__('Find a student')" :message="__('Search by name, admission number or phone to see what they owe.')" />
        </div>
    @endif
</div>
