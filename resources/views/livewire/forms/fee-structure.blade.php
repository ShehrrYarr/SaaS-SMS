<?php

/*
 * Create / edit a fee.
 *     $dispatch('open-modal', { component: 'forms.fee-structure', size: 'lg' })
 *     $dispatch('open-modal', { component: 'forms.fee-structure', params: { feeStructure: 3 }, size: 'lg' })
 *
 * Changes apply to invoices raised from now on; existing invoices keep their amounts.
 */

use App\Enums\FeeFrequency;
use App\Enums\LateFeeType;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\AcademicYear;
use App\Models\FeeStructure;
use App\Models\SchoolClass;
use App\Support\Billing\Money;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['feeStructure' => null])->locked();

state([
    'title' => '', 'description' => '', 'class_id' => '', 'academic_year_id' => '', 'amount' => '',
    'frequency' => FeeFrequency::Monthly->value, 'due_day' => '10', 'due_date' => '',
    'late_fee_mode' => 'default', 'late_fee_type' => LateFeeType::Fixed->value, 'late_fee_amount' => '', 'late_fee_grace_days' => '', 'late_fee_max' => '',
    'allow_discounts' => true, 'allow_sibling_discount' => true, 'is_active' => true,
]);

mount(function (?int $feeStructure = null) {
    if (! $feeStructure) {
        $this->authorize('fee-structures.create');
        $this->academic_year_id = (string) (AcademicYear::query()->current()->value('id') ?? '');

        return;
    }

    $this->authorize('fee-structures.edit');
    $fee = FeeStructure::query()->findOrFail($feeStructure);

    $this->feeStructure = $fee->id;
    $this->fill([
        'title' => $fee->title,
        'description' => (string) $fee->description,
        'class_id' => (string) ($fee->class_id ?? ''),
        'academic_year_id' => (string) ($fee->academic_year_id ?? ''),
        'amount' => Money::input($fee->amount),
        'frequency' => $fee->frequency->value,
        'due_day' => (string) ($fee->due_day ?? ''),
        'due_date' => (string) $fee->due_date?->toDateString(),
        'late_fee_mode' => match (true) {
            $fee->late_fee_type === null => 'default',
            (float) $fee->late_fee_amount <= 0 => 'none',
            default => 'custom',
        },
        'late_fee_type' => $fee->late_fee_type?->value ?? LateFeeType::Fixed->value,
        'late_fee_amount' => $fee->late_fee_amount !== null && (float) $fee->late_fee_amount > 0 ? Money::input($fee->late_fee_amount) : '',
        'late_fee_grace_days' => (string) ($fee->late_fee_grace_days ?? ''),
        'late_fee_max' => $fee->late_fee_max !== null ? Money::input($fee->late_fee_max) : '',
        'allow_discounts' => $fee->allow_discounts,
        'allow_sibling_discount' => $fee->allow_sibling_discount,
        'is_active' => $fee->is_active,
    ]);
});

$classes = computed(fn () => SchoolClass::query()->ordered()->pluck('name', 'id')->all());

$years = computed(fn () => AcademicYear::query()->orderByDesc('start_date')->pluck('name', 'id')->all());

$oneTime = computed(fn () => $this->frequency === FeeFrequency::OneTime->value);

rules(fn () => [
    'title' => ['required', 'string', 'max:150'],
    'description' => ['nullable', 'string', 'max:500'],
    'class_id' => ['nullable', Rule::exists('classes', 'id')->where('school_id', tenant()->id)->whereNull('deleted_at')],
    'academic_year_id' => ['nullable', Rule::exists('academic_years', 'id')->where('school_id', tenant()->id)->whereNull('deleted_at')],
    'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
    'frequency' => ['required', Rule::enum(FeeFrequency::class)],
    'due_day' => [$this->oneTime ? 'nullable' : 'required', 'integer', 'between:1,31'],
    'due_date' => ['nullable', 'date'],
    'late_fee_mode' => ['required', Rule::in(['default', 'none', 'custom'])],
    'late_fee_type' => [Rule::requiredIf($this->late_fee_mode === 'custom'), Rule::enum(LateFeeType::class)],
    'late_fee_amount' => [Rule::requiredIf($this->late_fee_mode === 'custom'), 'nullable', 'numeric', 'min:0.01', 'max:99999999', ...($this->late_fee_type === 'percentage' ? ['max:100'] : [])],
    'late_fee_grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],
    'late_fee_max' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
    'allow_discounts' => ['boolean'],
    'allow_sibling_discount' => ['boolean'],
    'is_active' => ['boolean'],
])->attributes([
    'class_id' => __('class'),
    'academic_year_id' => __('academic year'),
    'due_day' => __('due day'),
    'late_fee_amount' => __('late fee'),
]);

$save = function () {
    $this->authorize($this->feeStructure ? 'fee-structures.edit' : 'fee-structures.create');
    $data = $this->validate();
    $custom = $data['late_fee_mode'] === 'custom';

    $values = [
        'title' => trim($data['title']),
        'description' => filled($data['description']) ? trim($data['description']) : null,
        'class_id' => $data['class_id'] ?: null,
        'academic_year_id' => $data['academic_year_id'] ?: null,
        'amount' => $data['amount'],
        'frequency' => $data['frequency'],
        'due_day' => $this->oneTime ? null : (int) $data['due_day'],
        'due_date' => $this->oneTime && filled($data['due_date']) ? $data['due_date'] : null,
        'late_fee_type' => match ($data['late_fee_mode']) {
            'custom' => $data['late_fee_type'],
            'none' => LateFeeType::Fixed->value,
            default => null,
        },
        'late_fee_amount' => $custom ? $data['late_fee_amount'] : ($data['late_fee_mode'] === 'none' ? 0 : null),
        'late_fee_grace_days' => $custom && filled($data['late_fee_grace_days']) ? (int) $data['late_fee_grace_days'] : null,
        'late_fee_max' => $custom && filled($data['late_fee_max']) ? $data['late_fee_max'] : null,
        'allow_discounts' => $data['allow_discounts'],
        'allow_sibling_discount' => $data['allow_sibling_discount'],
        'is_active' => $data['is_active'],
    ];

    $this->feeStructure
        ? FeeStructure::query()->findOrFail($this->feeStructure)->update($values)
        : FeeStructure::query()->create($values);

    $this->closeModal();
    $this->refreshTables();
    $this->toast($this->feeStructure ? __('Fee updated.') : __('Fee created.'));
};

?>

@php
    $school = tenant();
    $symbol = $school->setting('currency_symbol');
    $lateFeesOn = (bool) $school->setting('fees.late_fee.enabled');
@endphp

<x-modal.form :title="$feeStructure ? __('Edit fee') : __('New fee')" :subtitle="$feeStructure ? __('Invoices already raised keep their amounts.') : null">
    <div class="row">
        <div class="col-sm-8"><x-form.input name="title" :label="__('Name')" required maxlength="150" :placeholder="__('e.g. Tuition Fee')" /></div>
        <div class="col-sm-4"><x-form.input name="amount" type="number" step="0.01" min="0.01" :label="__('Amount (:symbol)', ['symbol' => $symbol])" required /></div>
        <div class="col-sm-6"><x-form.select name="class_id" :label="__('Class')" :options="$this->classes" :placeholder="__('All classes')" /></div>
        <div class="col-sm-6"><x-form.select name="academic_year_id" :label="__('Academic year')" :options="$this->years" :placeholder="__('Every year')" /></div>
        <div class="col-sm-6"><x-form.select name="frequency" :label="__('Charged')" :options="App\Enums\FeeFrequency::options()" :placeholder="false" required live /></div>
        <div class="col-sm-6">
            @if ($this->oneTime)
                <x-form.input name="due_date" type="date" :label="__('Due date')" :hint="__('Billed in this month. Blank to bill it whenever you choose.')" />
            @else
                <x-form.input name="due_day" type="number" min="1" max="31" :label="__('Due on day')" required :hint="__('Day of the month payment is due.')" />
            @endif
        </div>
    </div>
    <x-form.textarea name="description" :label="__('Description')" rows="2" maxlength="500" />

    <h6 class="mb-3">{{ __('Reductions') }}</h6>
    <x-form.switch name="allow_discounts" :label="__('Discounts and scholarships apply')" />
    <x-form.switch name="allow_sibling_discount" :label="__('Sibling discount applies')" />

    <h6 class="mb-3">{{ __('Late fee') }}</h6>
    @unless ($lateFeesOn)
        <p class="text-muted fs-13">{{ __('Late fees are switched off for the school (School settings → Fee options), so none are charged at the moment.') }}</p>
    @endunless
    <x-form.select name="late_fee_mode" :label="__('Late fee for this fee')" :placeholder="false" live
                   :options="['default' => __('School\'s rule'), 'none' => __('No late fee'), 'custom' => __('Its own rule')]" />
    @if ($late_fee_mode === 'custom')
        <div class="row">
            <div class="col-sm-6"><x-form.select name="late_fee_type" :label="__('Charged as')" :options="App\Enums\LateFeeType::options()" :placeholder="false" required live /></div>
            <div class="col-sm-6">
                <x-form.input name="late_fee_amount" type="number" step="0.01" min="0" required
                              :label="match ($late_fee_type) { 'percentage' => __('Percentage of the fee'), 'daily' => __('Amount per day (:symbol)', ['symbol' => $symbol]), default => __('Amount (:symbol)', ['symbol' => $symbol]) }" />
            </div>
            <div class="col-sm-6"><x-form.input name="late_fee_grace_days" type="number" min="0" max="365" :label="__('Days of grace')" :hint="__('Blank to use the school\'s.')" /></div>
            <div class="col-sm-6"><x-form.input name="late_fee_max" type="number" step="0.01" min="0" :label="__('Cap (:symbol)', ['symbol' => $symbol])" :hint="__('Optional.')" /></div>
        </div>
    @endif

    <x-form.switch name="is_active" :label="__('Active')" :hint="__('Inactive fees aren\'t offered for new invoices.')" wrapper="mb-0" />
</x-modal.form>
