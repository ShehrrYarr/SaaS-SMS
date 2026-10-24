<?php

/*
 * Add a hostel room, or edit one.
 *     $dispatch('open-modal', { component: 'forms.hostel-room' })
 *     $dispatch('open-modal', { component: 'forms.hostel-room', params: { building: 2 } })
 *     $dispatch('open-modal', { component: 'forms.hostel-room', params: { room: 7 } })
 */

use App\Enums\RecordStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\HostelBuilding;
use App\Models\HostelRoom;
use App\Services\Hostel\Hostels;
use App\Tables\ActionFailed;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['room' => null])->locked();

state(['hostel_building_id' => '', 'room_number' => '', 'floor' => '', 'room_type' => '', 'capacity' => '2', 'fee' => '0', 'status' => RecordStatus::Active->value]);

mount(function (?int $room = null, ?int $building = null) {
    $this->authorize('hostel.manage');

    if ($room) {
        $record = HostelRoom::query()->findOrFail($room);

        $this->room = $record->id;
        $this->fill([
            ...array_map(fn ($value) => (string) $value, $record->only(['room_number', 'floor', 'room_type'])),
            'hostel_building_id' => (string) $record->hostel_building_id,
            'capacity' => (string) $record->capacity,
            'fee' => (string) (float) $record->fee,
            'status' => $record->status->value,
        ]);

        return;
    }

    $this->hostel_building_id = (string) ($building ? HostelBuilding::query()->findOrFail($building)->id : (array_key_first($this->buildings) ?? ''));
});

$buildings = computed(fn () => HostelBuilding::query()->orderBy('name')->pluck('name', 'id')->all());

rules(fn () => [
    'hostel_building_id' => ['required', Rule::in(array_map('strval', array_keys($this->buildings)))],
    'room_number' => [
        'required', 'string', 'max:30',
        Rule::unique('hostel_rooms', 'room_number')->where('hostel_building_id', (int) $this->hostel_building_id)->when(! $this->room, fn ($rule) => $rule->whereNull('deleted_at'))->ignore($this->room),
    ],
    'floor' => ['nullable', 'string', 'max:30'],
    'room_type' => ['nullable', 'string', 'max:50'],
    'capacity' => ['required', 'integer', 'min:1', 'max:50'],
    'fee' => ['required', 'numeric', 'min:0', 'max:99999999'],
    'status' => ['required', Rule::enum(RecordStatus::class)],
])->attributes([
    'hostel_building_id' => __('building'),
    'room_number' => __('room number'),
    'capacity' => __('beds'),
]);

$save = function (Hostels $hostels) {
    $this->authorize('hostel.manage');
    $data = $this->validate();

    try {
        $record = $hostels->saveRoom($data, $this->room ? HostelRoom::query()->findOrFail($this->room) : null);
    } catch (ActionFailed $e) {
        $this->addError('capacity', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->dispatch('hostel-updated');
    $this->toast($this->room ? __('Room updated.') : __('Room :number added.', ['number' => $record->room_number]));
};

?>

<x-modal.form :title="$room ? __('Edit room') : __('Add a room')">
    @if ($this->buildings === [])
        <div class="alert alert-warning mb-0">{{ __('Add a hostel building first.') }}</div>
    @else
        <div class="row">
            <div class="col-sm-7"><x-form.select name="hostel_building_id" :label="__('Building')" :options="$this->buildings" required /></div>
            <div class="col-sm-5"><x-form.input name="room_number" :label="__('Room number')" required maxlength="30" /></div>
            <div class="col-sm-6"><x-form.input name="floor" :label="__('Floor')" maxlength="30" :placeholder="__('e.g. Ground')" /></div>
            <div class="col-sm-6"><x-form.input name="room_type" :label="__('Type')" maxlength="50" :placeholder="__('e.g. Dormitory, Twin')" /></div>
            <div class="col-sm-4"><x-form.input name="capacity" type="number" min="1" :label="__('Beds')" required /></div>
            <div class="col-sm-4"><x-form.input name="fee" type="number" step="0.01" min="0" :label="__('Monthly fee')" required /></div>
            <div class="col-sm-4"><x-form.select name="status" :label="__('Status')" :options="RecordStatus::options()" :placeholder="false" required /></div>
        </div>
        <p class="text-muted fs-13 mb-0">{{ __('The fee is per student, billed on monthly invoices. An inactive room takes no new residents.') }}</p>
    @endif
</x-modal.form>
