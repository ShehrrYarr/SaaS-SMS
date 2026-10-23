<?php

/*
 * Create / edit a sibling discount rule.
 *     $dispatch('open-modal', { component: 'forms.sibling-rule' })
 *     $dispatch('open-modal', { component: 'forms.sibling-rule', params: { rule: 2 } })
 */

use App\Enums\AdjustmentType;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\FeeStructure;
use App\Models\SiblingDiscountRule;
use App\Support\Billing\Money;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['rule' => null])->locked();

state(['child_position' => '2', 'fee_structure_id' => '', 'type' => AdjustmentType::Percentage->value, 'value' => '', 'is_active' => true]);

mount(function (?int $rule = null) {
    $this->authorize('fee-discounts.manage');

    if ($rule) {
        $record = SiblingDiscountRule::query()->findOrFail($rule);

        $this->rule = $record->id;
        $this->fill([
            'child_position' => (string) $record->child_position,
            'fee_structure_id' => (string) ($record->fee_structure_id ?? ''),
            'type' => $record->type->value,
            'value' => Money::input($record->value),
            'is_active' => $record->is_active,
        ]);
    }
});

$fees = computed(fn () => FeeStructure::query()->orderBy('title')->pluck('title', 'id')->all());

rules(fn () => [
    'child_position' => ['required', 'integer', 'between:2,20'],
    'fee_structure_id' => ['nullable', Rule::exists('fee_structures', 'id')->where('school_id', tenant()->id)->whereNull('deleted_at')],
    'type' => ['required', Rule::enum(AdjustmentType::class)],
    'value' => ['required', 'numeric', 'min:0.01', $this->type === AdjustmentType::Percentage->value ? 'max:100' : 'max:99999999'],
    'is_active' => ['boolean'],
])->attributes(['child_position' => __('child'), 'fee_structure_id' => __('fee')]);

$save = function () {
    $this->authorize('fee-discounts.manage');
    $data = $this->validate();

    $values = [
        'child_position' => (int) $data['child_position'],
        'fee_structure_id' => $data['fee_structure_id'] ?: null,
        'type' => $data['type'],
        'value' => $data['value'],
        'is_active' => $data['is_active'],
    ];

    // One rule per child position for each fee (or for any fee).
    $taken = SiblingDiscountRule::query()
        ->where('child_position', $values['child_position'])
        ->where('fee_structure_id', $values['fee_structure_id'])
        ->when($this->rule, fn ($query) => $query->whereKeyNot($this->rule))
        ->exists();

    if ($taken) {
        $this->addError('child_position', __('There is already a rule for this child and fee.'));

        return;
    }

    $this->rule
        ? SiblingDiscountRule::query()->findOrFail($this->rule)->update($values)
        : SiblingDiscountRule::query()->create($values);

    $this->closeModal();
    $this->refreshTables();
    $this->toast($this->rule ? __('Rule updated.') : __('Rule added.'));
};

?>

<x-modal.form :title="$rule ? __('Edit sibling discount') : __('New sibling discount')">
    <p class="text-muted fs-13">{{ __('Children who share a parent are counted eldest first. Each child gets the rule for their place, or the nearest one before it: with rules for the 2nd and 3rd child, the 4th child gets the 3rd child\'s.') }}</p>
    <div class="row">
        <div class="col-sm-6">
            <x-form.select name="child_position" :label="__('From the')" :placeholder="false" required
                           :options="collect(range(2, 6))->mapWithKeys(fn ($n) => [$n => __('Child :position onwards', ['position' => $n])])->all()" />
        </div>
        <div class="col-sm-6"><x-form.select name="fee_structure_id" :label="__('Applies to')" :options="$this->fees" :placeholder="__('Any fee')" /></div>
        <div class="col-sm-6"><x-form.select name="type" :label="__('Type')" :options="AdjustmentType::options()" :placeholder="false" required live /></div>
        <div class="col-sm-6">
            <x-form.input name="value" type="number" step="0.01" min="0.01" required
                          :label="$type === 'percentage' ? __('Percentage off') : __('Amount off (:symbol)', ['symbol' => tenant()->setting('currency_symbol')])" />
        </div>
    </div>
    <x-form.switch name="is_active" :label="__('Active')" wrapper="mb-0" />
</x-modal.form>
