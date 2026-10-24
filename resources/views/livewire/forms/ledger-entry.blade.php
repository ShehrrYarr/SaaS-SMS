<?php

/*
 * Record / edit an expense or an income entry, with an optional proof file
 * (bill, receipt).
 *     $dispatch('open-modal', { component: 'forms.ledger-entry', params: { type: 'expense' } })
 *     $dispatch('open-modal', { component: 'forms.ledger-entry', params: { type: 'income', entry: 4 } })
 */

use App\Enums\FinanceCategoryType;
use App\Enums\PaymentMethod;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Support\Billing\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Support\Uploads;
use Livewire\WithFileUploads;

use function Livewire\Volt\{computed, mount, protect, rules, state, uses};

uses([InteractsWithUi::class, WithFileUploads::class]);

state(['type' => 'expense', 'entry' => null, 'currentProof' => null])->locked();

state([
    'title' => '', 'finance_category_id' => '', 'amount' => '', 'date' => '', 'payment_method' => PaymentMethod::Cash->value,
    'reference' => '', 'description' => '', 'proof' => null, 'remove_proof' => false,
]);

$kind = protect(fn () => FinanceCategoryType::from($this->type));

$record = protect(function () {
    $class = $this->kind()->modelClass();

    return $class::query()->whereNull('source_type')->findOrFail($this->entry);
});

mount(function (string $type, ?int $entry = null) {
    $kind = FinanceCategoryType::tryFrom($type) ?? abort(404);
    $this->type = $kind->value;
    $this->authorize($kind->permission($entry ? 'edit' : 'create'));

    if (! $entry) {
        $this->date = tenant()->today()->toDateString();

        return;
    }

    $this->entry = $entry;
    $record = $this->record();

    $this->currentProof = $record->proof_receipt;
    $this->fill([
        'title' => $record->title,
        'finance_category_id' => (string) $record->finance_category_id,
        'amount' => Money::input($record->amount),
        'date' => $record->{$kind->dateColumn()}->toDateString(),
        'payment_method' => $record->payment_method?->value ?? '',
        'reference' => (string) $record->reference,
        'description' => (string) $record->description,
    ]);
});

$categories = computed(fn () => FinanceCategory::query()
    ->where('type', $this->type)
    ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', (int) $this->finance_category_id))
    ->orderBy('name')
    ->pluck('name', 'id')
    ->all());

rules(fn () => [
    'title' => ['required', 'string', 'max:255'],
    'finance_category_id' => ['required', Rule::in(array_map('strval', array_keys($this->categories)))],
    'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
    'date' => ['required', 'date', 'before_or_equal:'.tenant()->today()->addYear()->toDateString()],
    'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
    'reference' => ['nullable', 'string', 'max:255'],
    'description' => ['nullable', 'string', 'max:1000'],
    'proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
    'remove_proof' => ['boolean'],
])->attributes([
    'finance_category_id' => __('category'),
    'payment_method' => __('method'),
    'proof' => __('proof'),
]);

$save = function () {
    $kind = $this->kind();
    $this->authorize($kind->permission($this->entry ? 'edit' : 'create'));
    $data = $this->validate();

    $values = [
        'title' => trim($data['title']),
        'finance_category_id' => (int) $data['finance_category_id'],
        'amount' => $data['amount'],
        $kind->dateColumn() => $data['date'],
        'payment_method' => $data['payment_method'] ?: null,
        'reference' => filled($data['reference']) ? trim($data['reference']) : null,
        'description' => filled($data['description']) ? trim($data['description']) : null,
    ];

    $old = $this->currentProof;

    if ($this->proof) {
        $values['proof_receipt'] = Uploads::store($this->proof, 'schools/'.tenant()->id.'/finance', Str::lower(Str::random(32)), ['pdf', ...Uploads::IMAGES], Expense::DISK);
    } elseif ($data['remove_proof']) {
        $values['proof_receipt'] = null;
    }

    DB::transaction(function () use ($kind, $values) {
        if ($this->entry) {
            $this->record()->update($values);
        } else {
            $class = $kind->modelClass();
            $class::query()->create([...$values, 'recorded_by' => auth()->id()]);
        }
    });

    if ($old && array_key_exists('proof_receipt', $values)) {
        Storage::disk(Expense::DISK)->delete($old);
    }

    $this->closeModal();
    $this->refreshTables();
    $this->toast(match (true) {
        $this->entry !== null => __('Entry updated.'),
        $kind === FinanceCategoryType::Income => __('Income recorded.'),
        default => __('Expense recorded.'),
    });
};

?>

@php($income = $type === 'income')

<x-modal.form :title="$entry ? ($income ? __('Edit income') : __('Edit expense')) : ($income ? __('Record income') : __('Record expense'))">
    <x-form.input name="title" :label="__('Description')" required maxlength="255" :placeholder="$income ? __('e.g. Annual day sponsorship') : __('e.g. Electricity bill, October')" />
    <div class="row">
        <div class="col-sm-6"><x-form.select name="finance_category_id" :label="__('Category')" :options="$this->categories" required /></div>
        <div class="col-sm-6"><x-form.input name="amount" type="number" step="0.01" min="0.01" :label="__('Amount (:symbol)', ['symbol' => tenant()->setting('currency_symbol')])" required /></div>
        <div class="col-sm-6"><x-form.input name="date" type="date" :label="__('Date')" required /></div>
        <div class="col-sm-6"><x-form.select name="payment_method" :label="__('Method')" :options="PaymentMethod::options()" :placeholder="__('Not recorded')" /></div>
    </div>
    <x-form.input name="reference" :label="__('Reference')" maxlength="255" :placeholder="$income ? __('e.g. Cheque no.') : __('e.g. Bill or voucher no.')" />
    <x-form.textarea name="description" :label="__('Notes')" rows="2" maxlength="1000" />

    <div class="mb-0">
        <label class="form-label" for="ledger-proof">{{ __('Proof') }}</label>
        @if ($currentProof && ! $remove_proof && ! $proof)
            <div class="d-flex align-items-center gap-2 mb-2">
                <a href="{{ route('accounting.ledger.proof', ['type' => $type, 'entry' => $entry]) }}" target="_blank" rel="noopener"><i class="ri-attachment-2 me-1"></i>{{ __('Current file') }}</a>
                <button type="button" class="btn btn-link btn-sm text-danger p-0" wire:click="$set('remove_proof', true)">{{ __('Remove') }}</button>
            </div>
        @endif
        <input type="file" id="ledger-proof" wire:model="proof" accept=".pdf,image/png,image/jpeg,image/webp" @class(['form-control', 'is-invalid' => $errors->has('proof')])>
        @error('proof') <div class="invalid-feedback">{{ $message }}</div> @enderror
        <div class="form-text">{{ __('A bill or receipt. PDF or image, up to 5 MB.') }}</div>
    </div>
</x-modal.form>
