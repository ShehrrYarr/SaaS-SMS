<?php

/*
 * Move a student to another class or section within the current year.
 *     $dispatch('open-modal', { component: 'forms.student-placement', params: { student: 7 } })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Services\Students\StudentRecords;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, rules, state, updated, uses};

uses([InteractsWithUi::class]);

state(['student' => null, 'name' => ''])->locked();

state(['class_id' => '', 'section_id' => '', 'roll_number' => '']);

mount(function (int $student) {
    $this->authorize('students.edit');

    $record = StudentProfile::query()->with('user')->findOrFail($student);

    $this->student = $record->id;
    $this->name = (string) $record->user?->name;
    $this->class_id = (string) ($record->class_id ?? '');
    $this->section_id = (string) ($record->section_id ?? '');
    $this->roll_number = (string) $record->roll_number;
});

$classes = computed(fn () => SchoolClass::query()->ordered()->with(['sections' => fn ($query) => $query->ordered()])->get());

$sections = computed(fn () => $this->classes->firstWhere('id', (int) $this->class_id)?->sections ?? collect());

updated(['class_id' => function () {
    $this->section_id = '';
    $this->roll_number = '';
}]);

rules(fn () => [
    'class_id' => ['required', Rule::exists('classes', 'id')->where('school_id', tenant()->id)->whereNull('deleted_at')],
    'section_id' => [$this->sections->isNotEmpty() ? 'required' : 'nullable', Rule::exists('sections', 'id')->where('class_id', (int) $this->class_id)->whereNull('deleted_at')],
    'roll_number' => ['nullable', 'string', 'max:20'],
])->attributes(['class_id' => __('class'), 'section_id' => __('section')]);

$save = function (StudentRecords $records) {
    $this->authorize('students.edit');
    $data = $this->validate();

    $records->move(StudentProfile::query()->findOrFail($this->student), (int) $data['class_id'], $data['section_id'] ?: null, $data['roll_number'] ?: null);

    $this->closeModal();
    $this->refreshTables();
    $this->toast(__(':name moved.', ['name' => $this->name]));
};

?>

<x-modal.form :title="__('Change class or section')" :subtitle="$name">
    <x-form.select name="class_id" :label="__('Class')" required live
                   :options="$this->classes->mapWithKeys(fn ($class) => [$class->id => $class->name])->all()" />
    @if ($this->sections->isNotEmpty())
        <x-form.select name="section_id" :label="__('Section')" required
                       :options="$this->sections->mapWithKeys(fn ($section) => [$section->id => $section->name])->all()" />
    @endif
    <x-form.input name="roll_number" :label="__('Roll number')" maxlength="20" wrapper="mb-3" />
    <p class="text-muted fs-13 mb-0">{{ __('For a move within this academic year. Attendance already taken stays with the old class. Use Promotion at the end of the year.') }}</p>
</x-modal.form>
