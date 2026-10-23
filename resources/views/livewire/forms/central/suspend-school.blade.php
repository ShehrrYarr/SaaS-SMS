<?php

/*
 * Suspend a school, with a reason (Super Admin).
 *     $dispatch('open-modal', { component: 'forms.central.suspend-school', params: { school: 3 } })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\School;
use App\Services\Schools\SchoolLifecycle;

use function Livewire\Volt\{mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['school' => null, 'name' => ''])->locked();

state(['reason' => '']);

mount(function (int $school) {
    abort_unless(tenancy()->isCentral(), 404);
    $this->authorize('manage-schools');

    $record = School::query()->findOrFail($school);

    $this->school = $record->id;
    $this->name = $record->name;
});

rules(['reason' => ['required', 'string', 'max:255']]);

$save = function (SchoolLifecycle $lifecycle) {
    $this->authorize('manage-schools');
    $this->validate();

    $school = School::query()->findOrFail($this->school);

    if ($school->isActive()) {
        $lifecycle->suspend($school, $this->reason, auth()->user());
    }

    $this->closeModal();
    $this->refreshTables();
    $this->toast(__(':name has been suspended.', ['name' => $school->name]), 'warning');
};

?>

<x-modal.form :title="__('Suspend :name', ['name' => $name])" :submit-label="__('Suspend school')" submit-color="warning"
              :subtitle="__('Everyone at the school is signed out of their portals until you reactivate it. No data is deleted.')">
    <x-form.textarea name="reason" :label="__('Reason')" rows="3" maxlength="255" required wrapper="mb-0"
                     :placeholder="__('e.g. Payment overdue since March')"
                     :hint="__('Recorded in the school\'s history and shown on its page here.')" />
</x-modal.form>
