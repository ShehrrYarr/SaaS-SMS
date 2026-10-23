<?php

/*
 * Create / edit a discount or scholarship definition.
 *     $dispatch('open-modal', { component: 'forms.fee-discount' })
 *     $dispatch('open-modal', { component: 'forms.fee-discount', params: { feeDiscount: 2 } })
 */

use App\Enums\AdjustmentType;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\FeeDiscount;
use App\Models\FeeStructure;
use App\Support\Billing\Money;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['feeDiscount' => null])->locked();

state(['name' => '', 'code' => '', 'fee_structure_id' => '', 'type' => AdjustmentType::Percentage->value, 'value' => '', 'description' => '', 'is_active' => true]);

mount(function (?int $feeDiscount = null) {
    $this->authorize('fee-discounts.manage');

    if ($feeDiscount) {
        $record = FeeDiscount::query()->findOrFail($feeDiscount);

        $this->feeDiscount = $record->id;
        $this->fill([
            'name' => $record->name,
            'code' => (string) $record->code,
            'fee_structure_id' => (string) ($record->fee_structure_id ?? ''),
            'type' => $record->type->value,
            'value' => Money::input($record->value),
            'description' => (string) $record->description,
            'is_active' => $record->is_active,
        ]);
    }
});

$fees = computed(fn () => FeeStructure::query()->orderBy('title')->pluck('title', 'id')->all());

rules(fn () => [
    'name' => ['required', 'string', 'max:100', Rule::unique('fee_discounts', 'name')->where('school_id', tenant()->id)->ignore($this->feeDiscount)],
    'code' => ['nullable', 'string', 'max:20'],
    'fee_structure_id' => ['nullable', Rule::exists('fee_structures', 'id')->where('school_id', tenant()->id)->whereNull('deleted_at')],
    'type' => ['required', Rule::enum(AdjustmentType::class)],
    'value' => ['required', 'numeric', 'min:0.01', $this->type === AdjustmentType::Percentage->value ? 'max:100' : 'max:99999999'],
    'description' => ['nullable', 'string', 'max:500'],
    'is_active' => ['boolean'],
])->attributes(['fee_structure_id' => __('fee')]);

$save = function () {
    $this->authorize('fee-discounts.manage');
    $data = $this->validate();

    $values = [
        'name' => trim($data['name']),
        'code' => filled($data['code']) ? strtoupper(trim($data['code'])) : null,
        'fee_structure_id' => $data['fee_structure_id'] ?: null,
        'type' => $data['type'],
        'value' => $data['value'],
        'description' => filled($data['description']) ? trim($data['description']) : null,
        'is_active' => $data['is_active'],
    ];

    $this->feeDiscount
        ? FeeDiscount::query()->findOrFail($this->feeDiscount)->update($values)
        : FeeDiscount::query()->create($values);

    $this->closeModal();
    $this->refreshTables();
    $this->toast($this->feeDiscount ? __('Discount updated.') : __('Discount created.'));
};

?>

<x-modal.form :title="$feeDiscount ? __('Edit discount') : __('New discount')">
    <div class="row">
        <div class="col-sm-8"><x-form.input name="name" :label="__('Name')" required maxlength="100" :placeholder="__('e.g. Merit Scholarship')" /></div>
        <div class="col-sm-4"><x-form.input name="code" :label="__('Code')" maxlength="20" /></div>
        <div class="col-sm-6"><x-form.select name="type" :label="__('Type')" :options="AdjustmentType::options()" :placeholder="false" required live /></div>
        <div class="col-sm-6">
            <x-form.input name="value" type="number" step="0.01" min="0.01" required
                          :label="$type === 'percentage' ? __('Percentage off') : __('Amount off (:symbol)', ['symbol' => tenant()->setting('currency_symbol')])" />
        </div>
    </div>
    <x-form.select name="fee_structure_id" :label="__('Applies to')" :options="$this->fees" :placeholder="__('Any fee that allows discounts')"
                   :hint="$type === 'fixed' ? __('A fixed amount comes off each fee it applies to.') : null" />
    <x-form.textarea name="description" :label="__('Description')" rows="2" maxlength="500" />
    <x-form.switch name="is_active" :label="__('Active')" :hint="__('Inactive discounts aren\'t applied to new invoices.')" wrapper="mb-0" />
</x-modal.form>
