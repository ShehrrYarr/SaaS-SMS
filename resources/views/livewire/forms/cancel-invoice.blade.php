<?php

/*
 * Cancel an invoice that has no payments, with a reason.
 *     $dispatch('open-modal', { component: 'forms.cancel-invoice', params: { invoice: 5 } })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Invoice;
use App\Services\Fees\Invoices;
use App\Tables\ActionFailed;

use function Livewire\Volt\{computed, mount, state, uses};

uses([InteractsWithUi::class]);

state(['invoice' => null])->locked();

state(['reason' => '']);

mount(function (int $invoice) {
    $this->authorize('invoices.cancel');
    $this->invoice = Invoice::query()->findOrFail($invoice)->id;
});

$record = computed(fn () => Invoice::query()->with('student.user')->findOrFail($this->invoice));

$save = function (Invoices $invoices) {
    $this->authorize('invoices.cancel');
    $this->validate(['reason' => ['required', 'string', 'max:255']]);

    try {
        $invoices->cancel($this->record, $this->reason, auth()->user());
    } catch (ActionFailed $e) {
        $this->addError('reason', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->dispatch('invoice-updated');
    $this->toast(__('Invoice cancelled.'));
};

?>

<x-modal.form :title="__('Cancel invoice :number', ['number' => $this->record->invoice_number])" :subtitle="$this->record->student?->user?->name"
              :submit-label="__('Cancel invoice')" submit-color="danger">
    <p class="text-muted">{{ __('The invoice stays on record as cancelled and is no longer owed. Its fees can be billed again.') }}</p>
    <x-form.input name="reason" :label="__('Reason')" required maxlength="255" wrapper="mb-0" :placeholder="__('e.g. Raised by mistake')" />
</x-modal.form>
