<?php

/*
 * Set (or clear) one period of a class/section's week.
 *     $dispatch('open-modal', { component: 'forms.timetable-slot', params: { classId: 3, sectionId: 7, day: 1, period: 2 } })
 *
 * Refuses a teacher or room that is already busy at that time.
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Classroom;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\SubjectClass;
use App\Models\Timetable;
use App\Services\Timetable\BellSchedule;
use App\Services\Timetable\Timetables;

use function Livewire\Volt\{computed, mount, state, uses};

uses([InteractsWithUi::class]);

state(['classId' => null, 'sectionId' => null, 'day' => null, 'period' => null, 'slot' => null])->locked();

state(['allocation' => '', 'classroom' => '']);

mount(function (int $classId, ?int $sectionId, int $day, int $period) {
    $this->authorize('timetable.manage');

    $record = SchoolClass::query()->findOrFail($classId);
    $sectionRecord = $sectionId ? Section::query()->where('class_id', $record->id)->findOrFail($sectionId) : null;

    abort_unless(app(BellSchedule::class)->period($period) && in_array($day, app(BellSchedule::class)->days(), true), 404);

    $this->fill(['classId' => $record->id, 'sectionId' => $sectionRecord?->id, 'day' => $day, 'period' => $period]);

    $year = app(Timetables::class)->year();
    abort_unless($year, 404);

    $existing = Timetable::query()->where('academic_year_id', $year->id)->forGroup($record->id, $sectionRecord?->id)
        ->where('day_of_week', $day)->where('period_number', $period)->first();

    $this->slot = $existing?->id;
    $this->allocation = (string) ($existing?->subject_class_id ?? '');
    $this->classroom = (string) ($existing?->classroom_id ?? $sectionRecord?->classroom_id ?? '');
});

$group = computed(fn () => [SchoolClass::query()->findOrFail($this->classId), $this->sectionId ? Section::query()->findOrFail($this->sectionId) : null]);

$allocations = computed(function () {
    [$class, $section] = $this->group;
    $year = app(Timetables::class)->year();

    return $year
        ? app(Timetables::class)->allocationsFor($class, $section, $year)
            ->mapWithKeys(fn (SubjectClass $allocation) => [$allocation->id => $allocation->subject?->name.' — '.($allocation->teacher?->user?->name ?? __('no teacher'))])
        : collect();
});

$classrooms = computed(fn () => Classroom::query()->orderBy('room_number')->get()->mapWithKeys(fn (Classroom $room) => [$room->id => $room->label()]));

$heading = computed(function () {
    [$class, $section] = $this->group;
    $times = app(BellSchedule::class)->period($this->period);

    return [
        'title' => $class->name.($section ? ' · '.$section->name : ''),
        'subtitle' => BellSchedule::dayName($this->day).' · '.__('Period :number', ['number' => $this->period]).($times ? ' · '.BellSchedule::label($times) : ''),
    ];
});

$save = function (Timetables $timetables) {
    $this->authorize('timetable.manage');

    $this->validate([
        'allocation' => ['required', 'integer'],
        'classroom' => ['nullable', 'integer'],
    ], ['allocation.required' => __('Pick the subject taught in this period.')]);

    [$class, $section] = $this->group;

    $timetables->assign($class, $section, $this->day, $this->period, (int) $this->allocation, $this->classroom !== '' ? (int) $this->classroom : null);

    $this->closeModal();
    $this->dispatch('timetable-updated');
    $this->toast(__('Timetable updated.'));
};

$clear = function (Timetables $timetables) {
    $this->authorize('timetable.manage');

    if ($this->slot && ($slot = Timetable::query()->find($this->slot))) {
        $timetables->clear($slot);
    }

    $this->closeModal();
    $this->dispatch('timetable-updated');
    $this->toast(__('Period cleared.'));
};

?>

<x-modal.form :title="$this->heading['title']" :subtitle="$this->heading['subtitle']">
    @if ($this->allocations->isEmpty())
        <div class="alert alert-warning fs-13 mb-0">
            {{ __('No subjects are allocated to this class this year. Allocate subjects and teachers under Subject Allocation first.') }}
        </div>
    @else
        <x-form.select name="allocation" :label="__('Subject and teacher')" :options="$this->allocations" required />
        <x-form.select name="classroom" :label="__('Room')" :options="$this->classrooms" :placeholder="__('No room')" wrapper="mb-0"
                       :hint="__('Optional. The section\'s own room is suggested.')" />
    @endif

    @if ($slot)
        <x-slot:footer>
            <button type="button" class="btn btn-outline-danger me-auto" wire:click="clear" wire:loading.attr="disabled" wire:target="clear">
                <i class="ri-eraser-line me-1"></i>{{ __('Clear period') }}
            </button>
        </x-slot:footer>
    @endif
</x-modal.form>
