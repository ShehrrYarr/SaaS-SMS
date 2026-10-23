<?php

/*
 * Void a fee receipt (a payment taken by mistake), with a reason.
 *     $dispatch('open-modal', { component: 'forms.void-payment', params: { payment: 7 } })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Payment;
use App\Services\Fees\FeeCollection;
use App\Support\Billing\Money;
use App\Tables\ActionFailed;

use function Livewire\Volt\{computed, mount, state, uses};

uses([InteractsWithUi::class]);

state(['payment' => null])->locked();

state(['reason' => '']);

mount(function (int $payment) {
    $this->authorize('fees.void');
    $this->payment = Payment::query()->findOrFail($payment)->id;
});

$record = computed(fn () => Payment::query()->with('invoice.student.user')->findOrFail($this->payment));

$save = function (FeeCollection $collection) {
    $this->authorize('fees.void');
    $this->validate(['reason' => ['required', 'string', 'max:255']]);

    try {
        $collection->void($this->record, $this->reason, auth()->user());
    } catch (ActionFailed $e) {
        $this->addError('reason', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->dispatch('invoice-updated');
    $this->toast(__('Receipt voided.'));
};

?>

<x-modal.form :title="__('Void receipt :number', ['number' => $this->record->receipt_number])"
              :subtitle="Money::school($this->record->amount_paid).' · '.$this->record->invoice?->student?->user?->name"
              :submit-label="__('Void receipt')" submit-color="danger">
    <p class="text-muted">{{ __('The receipt stays on record, marked void. The amount is owed again on invoice :invoice and taken out of income.', ['invoice' => $this->record->invoice?->invoice_number]) }}</p>
    <x-form.input name="reason" :label="__('Reason')" required maxlength="255" wrapper="mb-0" :placeholder="__('e.g. Cheque bounced')" />
</x-modal.form>
