<?php

/*
 * Add a bus route, or edit one: vehicle, fare and its stops in order with
 * pickup times.
 *     $dispatch('open-modal', { component: 'forms.transport-route', size: 'lg' })
 *     $dispatch('open-modal', { component: 'forms.transport-route', params: { route: 2 }, size: 'lg' })
 */

use App\Enums\RecordStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\TransportRoute;
use App\Models\TransportVehicle;
use App\Services\Transport\Transport;
use App\Tables\ActionFailed;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['route' => null])->locked();

state([
    'name' => '', 'transport_vehicle_id' => '', 'start_point' => '', 'end_point' => '', 'fare' => '0', 'description' => '',
    'stops' => [['name' => '', 'time' => '']],
]);

mount(function (?int $route = null) {
    $this->authorize('transport.manage');

    if ($route) {
        $record = TransportRoute::query()->findOrFail($route);

        $this->route = $record->id;
        $this->fill([
            ...array_map(fn ($value) => (string) $value, $record->only(['name', 'start_point', 'end_point', 'description'])),
            'transport_vehicle_id' => (string) ($record->transport_vehicle_id ?? ''),
            'fare' => (string) (float) $record->fare,
            'stops' => $record->stopList()->map(fn (array $stop) => ['name' => $stop['name'], 'time' => (string) $stop['time']])->all() ?: [['name' => '', 'time' => '']],
        ]);
    }
});

$vehicles = computed(fn () => TransportVehicle::query()
    ->where(fn ($query) => $query->where('status', RecordStatus::Active)->orWhere('id', (int) $this->transport_vehicle_id))
    ->orderBy('vehicle_number')
    ->get()
    ->mapWithKeys(fn (TransportVehicle $vehicle) => [$vehicle->id => $vehicle->label().' · '.trans_choice(':count seat|:count seats', $vehicle->capacity, ['count' => $vehicle->capacity])])
    ->all());

$addStop = fn () => $this->stops[] = ['name' => '', 'time' => ''];

$removeStop = function (int $index) {
    unset($this->stops[$index]);
    $this->stops = array_values($this->stops) ?: [['name' => '', 'time' => '']];
};

$moveStop = function (int $index, int $by) {
    $target = $index + $by;

    if (isset($this->stops[$index], $this->stops[$target])) {
        [$this->stops[$index], $this->stops[$target]] = [$this->stops[$target], $this->stops[$index]];
    }
};

rules(fn () => [
    'name' => [
        'required', 'string', 'max:100',
        Rule::unique('transport_routes', 'name')->where('school_id', tenant()->id)->when(! $this->route, fn ($rule) => $rule->whereNull('deleted_at'))->ignore($this->route),
    ],
    'transport_vehicle_id' => ['nullable', Rule::in(array_map('strval', array_keys($this->vehicles)))],
    'start_point' => ['nullable', 'string', 'max:255'],
    'end_point' => ['nullable', 'string', 'max:255'],
    'fare' => ['required', 'numeric', 'min:0', 'max:99999999'],
    'description' => ['nullable', 'string', 'max:1000'],
    'stops' => ['array', 'max:60'],
    'stops.*.name' => ['nullable', 'string', 'max:100', 'distinct:ignore_case'],
    'stops.*.time' => ['nullable', 'date_format:H:i'],
])->attributes([
    'transport_vehicle_id' => __('vehicle'),
    'stops.*.name' => __('stop'),
    'stops.*.time' => __('pickup time'),
]);

$save = function (Transport $transport) {
    $this->authorize('transport.manage');
    $data = $this->validate();

    try {
        $record = $transport->saveRoute($data, $this->route ? TransportRoute::query()->findOrFail($this->route) : null);
    } catch (ActionFailed $e) {
        $this->addError('stops', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->dispatch('transport-updated');
    $this->toast($this->route ? __('Route updated.') : __(':name added.', ['name' => $record->name]));
};

?>

<x-modal.form :title="$route ? __('Edit route') : __('New route')">
    <div class="row">
        <div class="col-md-6"><x-form.input name="name" :label="__('Route name')" required maxlength="100" :placeholder="__('e.g. Route 1 — Model Town')" /></div>
        <div class="col-md-6">
            <x-form.select name="transport_vehicle_id" :label="__('Vehicle')" :options="$this->vehicles" :placeholder="__('No vehicle yet')"
                           :hint="__('Its seats limit how many students can ride.')" />
        </div>
        <div class="col-md-4"><x-form.input name="start_point" :label="__('Starts at')" maxlength="255" /></div>
        <div class="col-md-4"><x-form.input name="end_point" :label="__('Ends at')" maxlength="255" :placeholder="__('e.g. School')" /></div>
        <div class="col-md-4"><x-form.input name="fare" type="number" step="0.01" min="0" :label="__('Monthly fare')" required :hint="__('Billed on monthly invoices. 0 for free.')" /></div>
    </div>

    <h6 class="mb-1">{{ __('Stops') }}</h6>
    <p class="text-muted fs-13 mb-3">{{ __('In the order the bus reaches them, with the morning pickup time.') }}</p>
    @error('stops') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

    @foreach ($stops as $index => $stop)
        <div class="row g-2 mb-2 align-items-start" wire:key="stop-{{ $index }}">
            <div class="col-auto pt-2 text-muted fs-13" style="width: 2rem">{{ $index + 1 }}.</div>
            <div class="col">
                <input type="text" wire:model="stops.{{ $index }}.name" maxlength="100" placeholder="{{ __('Stop name') }}" aria-label="{{ __('Stop name') }}"
                       @class(['form-control', 'is-invalid' => $errors->has("stops.$index.name")])>
                @error("stops.$index.name") <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-auto" style="width: 8.5rem">
                <input type="time" wire:model="stops.{{ $index }}.time" aria-label="{{ __('Pickup time') }}"
                       @class(['form-control', 'is-invalid' => $errors->has("stops.$index.time")])>
            </div>
            <div class="col-auto d-flex gap-1">
                <button type="button" class="btn btn-light icon-btn d-none d-sm-inline-flex" wire:click="moveStop({{ $index }}, -1)" @disabled($loop->first) aria-label="{{ __('Move up') }}"><i class="ri-arrow-up-line"></i></button>
                <button type="button" class="btn btn-light icon-btn d-none d-sm-inline-flex" wire:click="moveStop({{ $index }}, 1)" @disabled($loop->last) aria-label="{{ __('Move down') }}"><i class="ri-arrow-down-line"></i></button>
                <button type="button" class="btn btn-subtle-danger icon-btn" wire:click="removeStop({{ $index }})" aria-label="{{ __('Remove') }}"><i class="ri-delete-bin-line"></i></button>
            </div>
        </div>
    @endforeach
    <button type="button" class="btn btn-sm btn-light mb-4" wire:click="addStop"><i class="ri-add-line me-1"></i>{{ __('Add a stop') }}</button>

    <x-form.textarea name="description" :label="__('Notes')" rows="2" maxlength="1000" wrapper="mb-0" />
</x-modal.form>
