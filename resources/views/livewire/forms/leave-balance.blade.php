<?php

/*
 * Change one staff member's leave allowance for a year.
 *     $dispatch('open-modal', { component: 'forms.leave-balance', params: { balance: 9 } })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\LeaveBalance;

use function Livewire\Volt\{computed, mount, state, uses};

uses([InteractsWithUi::class]);

state(['balance' => null])->locked();

state(['allocated' => '']);

mount(function (int $balance) {
    $this->authorize('leave.manage');
    $record = LeaveBalance::query()->findOrFail($balance);

    $this->balance = $record->id;
    $this->allocated = rtrim(rtrim(number_format((float) $record->allocated, 1, '.', ''), '0'), '.');
});

$record = computed(fn () => LeaveBalance::query()->with(['staff.user', 'leaveType', 'academicYear'])->findOrFail($this->balance));

$save = function () {
    $this->authorize('leave.manage');
    $this->validate(['allocated' => ['required', 'numeric', 'min:0', 'max:366', 'multiple_of:0.5']], attributes: ['allocated' => __('days allowed')]);

    $record = $this->record;
    $old = (float) $record->allocated;
    $record->update(['allocated' => $this->allocated]);

    activity('leave_balance')->performedOn($record)->causedBy(auth()->user())
        ->withProperties(['staff' => $record->staff?->user?->name, 'type' => $record->leaveType?->name, 'from' => $old, 'to' => (float) $this->allocated])
        ->log('Leave allowance changed');

    $this->closeModal();
    $this->refreshTables();
    $this->toast(__('Allowance updated.'));
};

?>

<x-modal.form :title="__('Change allowance')"
              :subtitle="collect([$this->record->staff?->user?->name, $this->record->leaveType?->name, $this->record->academicYear?->name])->filter()->implode(' · ')">
    <x-form.input name="allocated" type="number" step="0.5" min="0" max="366" :label="__('Days allowed this year')" required wrapper="mb-2" />
    <p class="text-muted fs-13 mb-0">{{ __('Taken so far: :days. The school default is :default.', [
        'days' => rtrim(rtrim(number_format((float) $this->record->used, 1, '.', ''), '0'), '.'),
        'default' => $this->record->leaveType?->days_per_year ?? '—',
    ]) }}</p>
</x-modal.form>
