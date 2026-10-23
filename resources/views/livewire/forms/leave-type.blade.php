<?php

/*
 * Create / edit a leave type.
 *     $dispatch('open-modal', { component: 'forms.leave-type' })
 *     $dispatch('open-modal', { component: 'forms.leave-type', params: { leaveType: 2 } })
 */

use App\Enums\LeaveAppliesTo;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\LeaveType;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['leaveType' => null])->locked();

state([
    'name' => '', 'code' => '', 'applies_to' => LeaveAppliesTo::Staff->value, 'days_per_year' => '',
    'is_paid' => true, 'requires_attachment' => false, 'is_active' => true,
]);

mount(function (?int $leaveType = null) {
    $this->authorize('leave.manage');

    if ($leaveType) {
        $record = LeaveType::query()->findOrFail($leaveType);

        $this->leaveType = $record->id;
        $this->fill([
            'name' => $record->name,
            'code' => (string) $record->code,
            'applies_to' => $record->applies_to->value,
            'days_per_year' => (string) ($record->days_per_year ?? ''),
            'is_paid' => $record->is_paid,
            'requires_attachment' => $record->requires_attachment,
            'is_active' => $record->is_active,
        ]);
    }
});

rules(fn () => [
    'name' => ['required', 'string', 'max:100', Rule::unique('leave_types', 'name')->where('school_id', tenant()->id)->ignore($this->leaveType)],
    'code' => ['nullable', 'string', 'max:20'],
    'applies_to' => ['required', Rule::enum(LeaveAppliesTo::class)],
    'days_per_year' => ['nullable', 'integer', 'min:1', 'max:366'],
    'is_paid' => ['boolean'],
    'requires_attachment' => ['boolean'],
    'is_active' => ['boolean'],
]);

$save = function () {
    $this->authorize('leave.manage');
    $data = $this->validate();

    $data['code'] = filled($data['code']) ? strtoupper(trim($data['code'])) : null;
    $data['days_per_year'] = filled($data['days_per_year']) ? (int) $data['days_per_year'] : null;

    $this->leaveType
        ? LeaveType::query()->findOrFail($this->leaveType)->update($data)
        : LeaveType::query()->create($data);

    $this->closeModal();
    $this->refreshTables();
    $this->toast($this->leaveType ? __('Leave type updated.') : __('Leave type created.'));
};

?>

<x-modal.form :title="$leaveType ? __('Edit leave type') : __('New leave type')">
    <div class="row">
        <div class="col-sm-8"><x-form.input name="name" :label="__('Name')" required maxlength="100" :placeholder="__('e.g. Sick Leave')" /></div>
        <div class="col-sm-4"><x-form.input name="code" :label="__('Code')" maxlength="20" :placeholder="__('e.g. SL')" /></div>
        <div class="col-sm-6"><x-form.select name="applies_to" :label="__('For')" :options="LeaveAppliesTo::options()" :placeholder="false" required /></div>
        <div class="col-sm-6"><x-form.input name="days_per_year" type="number" min="1" max="366" :label="__('Days a year')" :hint="__('Staff quota. Blank for no limit.')" /></div>
    </div>
    <x-form.switch name="is_paid" :label="__('Paid leave')" :hint="__('Unpaid staff leave is deducted in payroll.')" />
    <x-form.switch name="requires_attachment" :label="__('Needs a supporting document')" :hint="__('e.g. a medical certificate.')" />
    <x-form.switch name="is_active" :label="__('Active')" :hint="__('Inactive types can\'t be chosen for new requests.')" wrapper="mb-0" />
</x-modal.form>
