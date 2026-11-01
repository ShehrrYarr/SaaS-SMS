<?php

/*
 * Receive a fee payment against an invoice; a receipt number is issued.
 *     $dispatch('open-modal', { component: 'forms.payment', params: { invoice: 5 } })
 *
 * Dispatches "payment-recorded" with the payment id, so the page can offer
 * the receipt.
 */

use App\Enums\PaymentMethod;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Invoice;
use App\Services\Fees\FeeCollection;
use App\Services\Fees\LateFees;
use App\Support\Billing\Money;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, protect, state, updated, uses};

uses([InteractsWithUi::class]);

state(['invoice' => null])->locked();

state(['amount' => '', 'payment_method' => PaymentMethod::Cash->value, 'payment_date' => '', 'reference' => '', 'notes' => '']);

mount(function (int $invoice) {
    $this->authorize('fees.collect');
    $record = Invoice::query()->findOrFail($invoice);

    abort_if($record->isCancelled(), 404);

    // Show the balance including any late fee due today.
    app(LateFees::class)->apply($record);

    $this->invoice = $record->id;
    $this->amount = Money::input(max($record->balanceAmount(), 0));
    $this->payment_date = tenant()->today()->toDateString();
});

$record = computed(fn () => Invoice::query()->with(['student.user', 'student.schoolClass', 'student.section'])->findOrFail($this->invoice));

// The invoice as it would stand on the chosen date (not saved).
$asOf = computed(function () {
    $invoice = $this->record;
    $on = strtotime((string) $this->payment_date) ? Carbon::parse($this->payment_date) : tenant()->today();
    $feesDue = round((float) $invoice->subtotal - (float) $invoice->discount_amount - (float) $invoice->paid_amount, 2);
    $late = $invoice->isOpen() && $feesDue > 0 ? app(LateFees::class)->amountFor($invoice, $on) : (float) $invoice->late_fee_amount;
    $total = (float) $invoice->subtotal - (float) $invoice->discount_amount + $late;

    return ['late' => $late, 'total' => $total, 'balance' => round($total - (float) $invoice->paid_amount, 2)];
});

updated(['payment_date' => function () {
    unset($this->asOf);
    $this->amount = Money::input(max($this->asOf['balance'], 0));
}]);

$save = function (FeeCollection $collection) {
    $this->authorize('fees.collect');
    $data = $this->validate([
        'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
        'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
        'payment_date' => ['required', 'date', 'after_or_equal:'.$this->record->issue_date->toDateString(), 'before_or_equal:'.tenant()->today()->toDateString()],
        'reference' => ['nullable', 'string', 'max:100'],
        'notes' => ['nullable', 'string', 'max:500'],
    ], ['payment_date.after_or_equal' => __('The payment date can\'t be before the invoice was issued.')], ['payment_method' => __('method'), 'payment_date' => __('date')]);

    try {
        $payment = $collection->collect($this->record, $data['amount'], PaymentMethod::from($data['payment_method']), Carbon::parse($data['payment_date']), auth()->user(), $data['reference'], $data['notes']);
    } catch (ActionFailed $e) {
        $this->addError('amount', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->dispatch('payment-recorded', payment: $payment->id);
    $this->dispatch('invoice-updated');
    $this->toast(__('Payment received. Receipt :number.', ['number' => $payment->receipt_number]));
};

?>

@php
    $invoice = $this->record;
    $asOf = $this->asOf;
    $symbol = tenant()->setting('currency_symbol');
@endphp

<x-modal.form :title="__('Receive payment')" :subtitle="$invoice->invoice_number.' · '.$invoice->student?->user?->name" :submit-label="__('Receive')" submit-color="success">
    <dl class="row mb-4 fs-13">
        <dt class="col-6 text-muted fw-normal">{{ __('Invoice total') }}</dt><dd class="col-6 text-end mb-1">{{ Money::school($asOf['total']) }}</dd>
        @if ($asOf['late'] > 0)
            <dt class="col-6 text-muted fw-normal">{{ __('Includes late fee') }}</dt><dd class="col-6 text-end mb-1 text-danger">{{ Money::school($asOf['late']) }}</dd>
        @endif
        <dt class="col-6 text-muted fw-normal">{{ __('Already paid') }}</dt><dd class="col-6 text-end mb-1">{{ Money::school($invoice->paid_amount) }}</dd>
        <dt class="col-6">{{ __('Balance') }}</dt><dd class="col-6 text-end mb-0 fw-semibold">{{ Money::school($asOf['balance']) }}</dd>
    </dl>

    <div class="row">
        <div class="col-sm-6"><x-form.input name="amount" type="number" step="0.01" min="0.01" :label="__('Amount received (:symbol)', ['symbol' => $symbol])" required /></div>
        <div class="col-sm-6"><x-form.input name="payment_date" type="date" :label="__('Date')" required live :min="$invoice->issue_date->toDateString()" :max="tenant()->today()->toDateString()" /></div>
        <div class="col-sm-6"><x-form.select name="payment_method" :label="__('Method')" :options="PaymentMethod::options()" :placeholder="false" required /></div>
        <div class="col-sm-6"><x-form.input name="reference" :label="__('Reference')" maxlength="100" :placeholder="__('Cheque or transaction no.')" /></div>
    </div>
    <x-form.input name="notes" :label="__('Notes')" maxlength="500" wrapper="mb-0" />
</x-modal.form>
