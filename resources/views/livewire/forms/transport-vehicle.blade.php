<?php

/*
 * Add a bus or van, or edit one.
 *     $dispatch('open-modal', { component: 'forms.transport-vehicle' })
 *     $dispatch('open-modal', { component: 'forms.transport-vehicle', params: { vehicle: 2 } })
 */

use App\Enums\RecordStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\TransportVehicle;
use App\Services\Transport\Transport;
use App\Tables\ActionFailed;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['vehicle' => null])->locked();

state(['vehicle_number' => '', 'model' => '', 'capacity' => '', 'driver_name' => '', 'driver_phone' => '', 'driver_license' => '', 'status' => RecordStatus::Active->value]);

mount(function (?int $vehicle = null) {
    $this->authorize('transport.manage');

    if ($vehicle) {
        $record = TransportVehicle::query()->findOrFail($vehicle);

        $this->vehicle = $record->id;
        $this->fill([
            ...array_map(fn ($value) => (string) $value, $record->only(['vehicle_number', 'model', 'driver_name', 'driver_phone', 'driver_license'])),
            'capacity' => (string) $record->capacity,
            'status' => $record->status->value,
        ]);
    }
});

rules(fn () => [
    'vehicle_number' => [
        'required', 'string', 'max:30',
        // A new vehicle may reuse a removed one's number (it is brought back); a renamed one can't.
        Rule::unique('transport_vehicles', 'vehicle_number')->where('school_id', tenant()->id)->when(! $this->vehicle, fn ($rule) => $rule->whereNull('deleted_at'))->ignore($this->vehicle),
    ],
    'model' => ['nullable', 'string', 'max:100'],
    'capacity' => ['required', 'integer', 'min:1', 'max:200'],
    'driver_name' => ['nullable', 'string', 'max:100'],
    'driver_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]+$/'],
    'driver_license' => ['nullable', 'string', 'max:50'],
    'status' => ['required', Rule::enum(RecordStatus::class)],
])->attributes([
    'vehicle_number' => __('registration number'),
    'capacity' => __('seats'),
    'driver_phone' => __('driver\'s phone'),
])->messages([
    'driver_phone.regex' => __('Use digits, spaces, +, dashes or brackets.'),
]);

$save = function (Transport $transport) {
    $this->authorize('transport.manage');
    $data = $this->validate();

    try {
        $record = $transport->saveVehicle($data, $this->vehicle ? TransportVehicle::query()->findOrFail($this->vehicle) : null);
    } catch (ActionFailed $e) {
        $this->addError('capacity', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->dispatch('transport-updated');
    $this->toast($this->vehicle ? __('Vehicle updated.') : __(':number added.', ['number' => $record->vehicle_number]));
};

?>

<x-modal.form :title="$vehicle ? __('Edit vehicle') : __('Add a vehicle')">
    <div class="row">
        <div class="col-sm-6"><x-form.input name="vehicle_number" :label="__('Registration number')" required maxlength="30" :placeholder="__('e.g. LEB-1234')" /></div>
        <div class="col-sm-6"><x-form.input name="model" :label="__('Make & model')" maxlength="100" :placeholder="__('e.g. Toyota Coaster')" /></div>
        <div class="col-sm-6"><x-form.input name="capacity" type="number" min="1" :label="__('Seats for students')" required /></div>
        <div class="col-sm-6"><x-form.select name="status" :label="__('Status')" :options="RecordStatus::options()" :placeholder="false" required /></div>
    </div>

    <h6 class="mb-3">{{ __('Driver') }}</h6>
    <div class="row">
        <div class="col-sm-6"><x-form.input name="driver_name" :label="__('Name')" maxlength="100" /></div>
        <div class="col-sm-6"><x-form.input name="driver_phone" type="tel" :label="__('Phone')" maxlength="30" :hint="__('Shown to parents of students on its routes.')" /></div>
        <div class="col-sm-6"><x-form.input name="driver_license" :label="__('Licence number')" maxlength="50" wrapper="mb-0" /></div>
    </div>
</x-modal.form>
