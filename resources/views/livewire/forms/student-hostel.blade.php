<?php

/*
 * Give a student a hostel bed, or move a resident to another room or bed.
 *     $dispatch('open-modal', { component: 'forms.student-hostel' })
 *     $dispatch('open-modal', { component: 'forms.student-hostel', params: { room: 7 } })
 *     $dispatch('open-modal', { component: 'forms.student-hostel', params: { student: 12 } })
 *     $dispatch('open-modal', { component: 'forms.student-hostel', params: { stay: 4 } })
 *
 * A student who already has a room is moved rather than given a second one.
 */

use App\Enums\RecordStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Livewire\Concerns\PicksStudent;
use App\Models\HostelBuilding;
use App\Models\HostelRoom;
use App\Models\StudentHostel;
use App\Models\StudentProfile;
use App\Services\Hostel\Hostels;
use App\Support\Billing\Money;
use App\Tables\ActionFailed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, protect, rules, state, updated, uses};

uses([InteractsWithUi::class, PicksStudent::class]);

state(['current' => null, 'fixedStudent' => false])->locked();

state(['building' => '', 'hostel_room_id' => '', 'bed_number' => '', 'from' => '']);

mount(function (?int $student = null, ?int $room = null, ?int $stay = null) {
    $this->authorize('hostel.manage');
    $this->from = tenant()->today()->toDateString();

    if ($stay) {
        $record = StudentHostel::query()->active()->with('student')->findOrFail($stay);
        $this->fixedStudent = true;
        $this->setPickedStudent($record->student);
        $this->studentPicked($record->student);

        return;
    }

    if ($student) {
        $this->fixedStudent = true;
        $profile = StudentProfile::query()->active()->findOrFail($student);
        $this->setPickedStudent($profile);
        $this->studentPicked($profile);
    }

    if ($room) {
        $record = HostelRoom::query()->findOrFail($room);
        $this->building = (string) $record->hostel_building_id;
        $this->hostel_room_id = (string) $record->id;
    }

    $this->building = $this->building ?: (string) (array_key_first($this->buildings) ?? '');
});

$pickableStudents = protect(fn (): Builder => StudentProfile::query()->active());

// A student who already has a room: the form moves them.
$studentPicked = protect(function (StudentProfile $student) {
    $active = StudentHostel::query()->active()->with('room')->where('student_profile_id', $student->id)->first();

    $this->current = $active?->id;

    if ($active) {
        $this->building = (string) $active->room?->hostel_building_id;
        $this->hostel_room_id = (string) $active->hostel_room_id;
        $this->bed_number = (string) $active->bed_number;
    }

    unset($this->student, $this->buildings, $this->rooms, $this->roomOptions, $this->bedOptions);

    // Keep to buildings that admit them.
    if (! $active && $this->building !== '' && ! array_key_exists($this->building, $this->buildings)) {
        $this->building = (string) (array_key_first($this->buildings) ?? '');
        $this->hostel_room_id = '';
        $this->bed_number = '';
    }
});

$student = computed(fn () => $this->pickedStudent());

$currentStay = computed(fn () => $this->current ? StudentHostel::query()->with('room.building')->find($this->current) : null);

// Buildings that admit the picked student.
$buildings = computed(fn () => HostelBuilding::query()->orderBy('name')->get()
    ->filter(fn (HostelBuilding $building) => ! $this->student || $building->admits($this->student->gender))
    ->mapWithKeys(fn (HostelBuilding $building) => [$building->id => $building->name.' · '.$building->type->label()])
    ->all());

$rooms = computed(fn () => $this->building === '' ? collect() : HostelRoom::query()
    ->where('hostel_building_id', (int) $this->building)
    ->where(fn ($query) => $query->where('status', RecordStatus::Active)->orWhere('id', (int) $this->hostel_room_id))
    ->withCount('activeAllocations')
    ->orderBy('room_number')
    ->get());

$roomOptions = computed(fn () => $this->rooms->mapWithKeys(function (HostelRoom $room) {
    $free = max(0, $room->capacity - $room->active_allocations_count + ($room->id === $this->currentStay?->hostel_room_id ? 1 : 0));

    return [$room->id => $room->label(false).' · '.($free > 0 ? trans_choice(':count bed free|:count beds free', $free, ['count' => $free]) : __('full')).' · '.Money::school($room->fee)];
})->all());

$bedOptions = computed(function () {
    $room = $this->rooms->firstWhere('id', (int) $this->hostel_room_id);

    if (! $room) {
        return [];
    }

    $beds = app(Hostels::class)->freeBedNumbers($room, $this->current);

    if ($this->bed_number !== '' && ! in_array($this->bed_number, $beds, true) && $room->id === $this->currentStay?->hostel_room_id) {
        $beds[] = $this->bed_number;
    }

    return collect($beds)->mapWithKeys(fn (string $bed) => [$bed => __('Bed :bed', ['bed' => $bed])])->all();
});

updated([
    'building' => function () {
        $this->hostel_room_id = '';
        $this->bed_number = '';
    },
    'hostel_room_id' => function () {
        unset($this->bedOptions);
        $this->bed_number = (string) (array_key_first($this->bedOptions) ?? '');
    },
]);

rules(fn () => [
    'student_id' => ['required'],
    'building' => ['required', Rule::in(array_map('strval', array_keys($this->buildings)))],
    'hostel_room_id' => ['required', Rule::in(array_map('strval', array_keys($this->roomOptions)))],
    'bed_number' => ['nullable', 'string', 'max:10'],
    'from' => ['required', 'date'],
])->attributes([
    'student_id' => __('student'),
    'hostel_room_id' => __('room'),
    'bed_number' => __('bed'),
    'from' => __('date'),
]);

$save = function (Hostels $hostels) {
    $this->authorize('hostel.manage');
    $data = $this->validate();

    $student = $this->pickedStudent() ?? abort(404);
    $room = HostelRoom::query()->with('building')->findOrFail((int) $data['hostel_room_id']);
    $from = Carbon::parse($data['from']);

    try {
        $this->current
            ? $hostels->move(StudentHostel::query()->findOrFail($this->current), $room, $data['bed_number'], $from, auth()->user())
            : $hostels->allocate($student, $room, $data['bed_number'], $from, auth()->user());
    } catch (ActionFailed $e) {
        $this->addError('hostel_room_id', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->dispatch('hostel-updated');
    $this->toast($this->current
        ? __(':name moved to :room.', ['name' => $student->user?->name, 'room' => $room->label()])
        : __(':name given :room.', ['name' => $student->user?->name, 'room' => $room->label()]));
};

?>

<x-modal.form :title="$current ? __('Move room') : __('Allocate a hostel bed')" :submit-label="$current ? __('Move') : __('Allocate')">
    <x-form.student-picker :results="$this->studentResults" :selected="$studentLabel" :picked="$student_id" :search="$studentSearch" :locked="$fixedStudent" />

    @if ($this->currentStay)
        <div class="alert alert-info py-2 fs-13">
            <i class="ri-hotel-bed-line me-1"></i>
            {{ __('Stays in :room since :date.', ['room' => $this->currentStay->room?->label(), 'date' => $this->currentStay->check_in->format(tenant()->setting('date_format'))]) }}
            {{ __('Moving to another room ends that stay the day before.') }}
        </div>
    @endif

    @if ($this->buildings === [])
        <div class="alert alert-warning">{{ $this->student ? __('No hostel building admits this student.') : __('Add a hostel building and rooms first.') }}</div>
    @else
        <div class="row">
            <div class="col-sm-6"><x-form.select name="building" :label="__('Building')" :options="$this->buildings" :placeholder="false" required live /></div>
            <div class="col-sm-6">
                <x-form.select name="hostel_room_id" :label="__('Room')" :options="$this->roomOptions" required live />
            </div>
            <div class="col-sm-6">
                <x-form.select name="bed_number" :label="__('Bed')" :options="$this->bedOptions" :placeholder="__('Any free bed')" />
            </div>
            <div class="col-sm-6"><x-form.input name="from" type="date" :label="$current ? __('Move on') : __('Check-in date')" required /></div>
        </div>
    @endif
</x-modal.form>
