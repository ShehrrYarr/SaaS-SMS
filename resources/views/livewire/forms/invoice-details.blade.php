<?php

/*
 * Change an invoice's due date and notes. Amounts are fixed once raised:
 * cancel and raise a new invoice to change what is billed.
 *     $dispatch('open-modal', { component: 'forms.invoice-details', params: { invoice: 5 } })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Invoice;
use App\Services\Fees\Invoices;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;

use function Livewire\Volt\{mount, state, uses};

uses([InteractsWithUi::class]);

state(['invoice' => null, 'number' => ''])->locked();

state(['due_date' => '', 'notes' => '']);

mount(function (int $invoice) {
    $this->authorize('invoices.edit');
    $record = Invoice::query()->findOrFail($invoice);

    $this->invoice = $record->id;
    $this->number = $record->invoice_number;
    $this->due_date = $record->due_date->toDateString();
    $this->notes = (string) $record->notes;
});

$save = function (Invoices $invoices) {
    $this->authorize('invoices.edit');
    $data = $this->validate([
        'due_date' => ['required', 'date'],
        'notes' => ['nullable', 'string', 'max:1000'],
    ]);

    try {
        $invoices->updateDetails(Invoice::query()->findOrFail($this->invoice), Carbon::parse($data['due_date']), $data['notes'], auth()->user());
    } catch (ActionFailed $e) {
        $this->addError('due_date', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->dispatch('invoice-updated');
    $this->refreshTables();
    $this->toast(__('Invoice updated.'));
};

?>

<x-modal.form :title="__('Edit invoice :number', ['number' => $number])" :subtitle="__('To change the fees billed, cancel this invoice and raise a new one.')">
    <x-form.input name="due_date" type="date" :label="__('Due date')" required />
    <x-form.textarea name="notes" :label="__('Notes')" rows="3" maxlength="1000" wrapper="mb-0" />
</x-modal.form>
