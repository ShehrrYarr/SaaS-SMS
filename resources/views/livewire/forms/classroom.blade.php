<?php

/*
 * Create / edit a classroom (room, lab, hall...).
 *     $dispatch('open-modal', { component: 'forms.classroom', params: { classroom: 2 } })
 */

use App\Enums\ClassroomType;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Campus;
use App\Models\Classroom;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['classroom' => null])->locked();

state(['campus_id' => '', 'room_number' => '', 'name' => '', 'building' => '', 'floor' => '', 'capacity' => '', 'type' => ClassroomType::Classroom->value]);

mount(function (?int $classroom = null) {
    if (! $classroom) {
        $this->authorize('classrooms.create');
        $this->campus_id = (string) (Campus::main()?->id ?? '');

        return;
    }

    $this->authorize('classrooms.edit');
    $record = Classroom::query()->findOrFail($classroom);

    $this->classroom = $record->id;
    $this->fill([
        ...$record->only(['room_number', 'name', 'building', 'floor']),
        'campus_id' => (string) ($record->campus_id ?? ''),
        'capacity' => (string) ($record->capacity ?? ''),
        'type' => $record->type->value,
    ]);
});

$campuses = computed(fn () => Campus::query()->ordered()->pluck('name', 'id')->all());

rules(fn () => [
    'campus_id' => ['required', Rule::exists('campuses', 'id')->where('school_id', tenant()->id)->whereNull('deleted_at')],
    'room_number' => [
        'required', 'string', 'max:30',
        Rule::unique('classrooms', 'room_number')->where('school_id', tenant()->id)->where('campus_id', $this->campus_id ?: null)->ignore($this->classroom),
    ],
    'name' => ['nullable', 'string', 'max:100'],
    'building' => ['nullable', 'string', 'max:100'],
    'floor' => ['nullable', 'string', 'max:20'],
    'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
    'type' => ['required', Rule::enum(ClassroomType::class)],
])->attributes(['campus_id' => __('campus'), 'room_number' => __('room number')]);

$save = function () {
    $this->authorize($this->classroom ? 'classrooms.edit' : 'classrooms.create');
    $data = array_map(fn ($value) => $value === '' ? null : $value, $this->validate());

    $this->classroom
        ? Classroom::query()->findOrFail($this->classroom)->update($data)
        : Classroom::query()->create($data);

    $this->closeModal();
    $this->refreshTables();
    $this->toast($this->classroom ? __('Room updated.') : __('Room added.'));
};

?>

<x-modal.form :title="$classroom ? __('Edit room') : __('New room')">
    <div class="row">
        <div class="col-sm-5"><x-form.input name="room_number" :label="__('Room number')" required maxlength="30" :placeholder="__('e.g. 101')" /></div>
        <div class="col-sm-7"><x-form.input name="name" :label="__('Name')" maxlength="100" :placeholder="__('e.g. Physics Lab')" /></div>
        <div class="col-sm-6"><x-form.select name="type" :label="__('Type')" :options="ClassroomType::options()" :placeholder="false" required /></div>
        <div class="col-sm-6"><x-form.input name="capacity" type="number" min="1" :label="__('Seats')" /></div>
        <div class="col-sm-6"><x-form.input name="building" :label="__('Building')" maxlength="100" /></div>
        <div class="col-sm-6"><x-form.input name="floor" :label="__('Floor')" maxlength="20" /></div>
    </div>

    <x-form.select name="campus_id" :label="__('Campus')" :options="$this->campuses" required wrapper="mb-0" />
</x-modal.form>
