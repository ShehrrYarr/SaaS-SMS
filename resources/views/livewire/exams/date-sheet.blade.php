<?php

/*
 * An examination's date sheet, one class at a time: tick the subjects
 * examined and set date, time, room and marks.
 *     <livewire:exams.date-sheet :examination="$exam->id" />
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Classroom;
use App\Models\ExamSchedule;
use App\Models\Examination;
use App\Models\SchoolClass;
use App\Services\Exams\ExamSchedules;
use App\Services\Exams\Gradebook;
use App\Tables\ActionFailed;

use function Livewire\Volt\{computed, mount, protect, state, updated, uses};

uses([InteractsWithUi::class]);

state(['examination' => null])->locked();

state([
    'class_id' => '',
    // subject id => ['on' => bool, 'exam_date', 'start_time', 'end_time', 'classroom_id', 'max_marks', 'passing_marks']
    'rows' => [],
    'defaultMax' => '100', 'defaultPass' => '33',
]);

$exam = computed(fn () => Examination::query()->findOrFail($this->examination));

$classes = computed(fn () => SchoolClass::query()->ordered()->get());

$subjects = computed(fn () => $this->class_id
    ? app(ExamSchedules::class)->subjectsFor($this->exam, SchoolClass::query()->findOrFail($this->class_id))
    : collect());

$rooms = computed(fn () => Classroom::query()->orderBy('room_number')->get()
    ->mapWithKeys(fn (Classroom $room) => [$room->id => $room->room_number.($room->name ? ' · '.$room->name : '')])->all());

$canEdit = computed(fn () => auth()->user()->can('examinations.edit') && ! $this->exam->isPublished());

// Classes that already have a date sheet, with how many papers.
$scheduled = computed(fn () => ExamSchedule::query()->where('examination_id', $this->examination)
    ->selectRaw('class_id, count(*) as papers')->groupBy('class_id')->toBase()->pluck('papers', 'class_id'));

$load = protect(function () {
    unset($this->subjects);

    $existing = ExamSchedule::query()->where('examination_id', $this->examination)->where('class_id', (int) $this->class_id)->get()->keyBy('subject_id');

    $this->rows = $this->subjects->mapWithKeys(function ($subject) use ($existing) {
        $schedule = $existing->get($subject->id);

        return [$subject->id => [
            'on' => (bool) $schedule || $existing->isEmpty(),
            'exam_date' => (string) $schedule?->exam_date?->toDateString(),
            'start_time' => $schedule?->start_time ? substr($schedule->start_time, 0, 5) : '',
            'end_time' => $schedule?->end_time ? substr($schedule->end_time, 0, 5) : '',
            'classroom_id' => (string) ($schedule?->classroom_id ?? ''),
            'max_marks' => $schedule ? Gradebook::trim($schedule->max_marks) : $this->defaultMax,
            'passing_marks' => $schedule ? Gradebook::trim($schedule->passing_marks) : $this->defaultPass,
        ]];
    })->all();
});

mount(function (int $examination) {
    $this->authorize('examinations.view');
    $this->examination = Examination::query()->findOrFail($examination)->id;
    $this->class_id = (string) ($this->classes->first()?->id ?? '');

    if ($this->class_id) {
        $this->load();
    }
});

updated(['class_id' => fn () => $this->load()]);

// Apply the default marks to every ticked subject.
$applyDefaults = function () {
    $this->validate(['defaultMax' => ['required', 'numeric', 'min:1', 'max:1000'], 'defaultPass' => ['required', 'numeric', 'min:0', 'lte:defaultMax']]);

    foreach ($this->rows as $id => $row) {
        $this->rows[$id]['max_marks'] = $this->defaultMax;
        $this->rows[$id]['passing_marks'] = $this->defaultPass;
    }
};

$save = function (ExamSchedules $schedules) {
    abort_unless($this->canEdit, 403);

    $exam = $this->exam;
    $this->validate([
        'class_id' => ['required', 'integer'],
        'rows.*.on' => ['boolean'],
        'rows.*.exam_date' => ['nullable', 'date', 'after_or_equal:'.$exam->start_date->toDateString(), 'before_or_equal:'.$exam->end_date->toDateString()],
        'rows.*.start_time' => ['nullable', 'date_format:H:i'],
        'rows.*.end_time' => ['nullable', 'date_format:H:i'],
        'rows.*.classroom_id' => ['nullable', 'integer'],
        'rows.*.max_marks' => ['required_if:rows.*.on,true', 'nullable', 'numeric', 'min:1', 'max:1000'],
        'rows.*.passing_marks' => ['required_if:rows.*.on,true', 'nullable', 'numeric', 'min:0'],
    ], [
        'rows.*.exam_date.after_or_equal' => __('Pick a date within the examination.'),
        'rows.*.exam_date.before_or_equal' => __('Pick a date within the examination.'),
    ], [
        'rows.*.max_marks' => __('total marks'),
        'rows.*.passing_marks' => __('passing marks'),
    ]);

    $chosen = collect($this->rows)->filter(fn ($row) => $row['on'])
        ->only($this->subjects->modelKeys())
        ->map(fn ($row) => [...$row, 'classroom_id' => array_key_exists((int) $row['classroom_id'], $this->rooms) ? $row['classroom_id'] : null]);

    foreach ($chosen as $id => $row) {
        if ((float) $row['passing_marks'] > (float) $row['max_marks']) {
            $this->addError("rows.{$id}.passing_marks", __('Passing marks can\'t be more than the total.'));

            return;
        }
    }

    try {
        $schedules->sync($exam, SchoolClass::query()->findOrFail($this->class_id), $chosen->all());
    } catch (ActionFailed $e) {
        $this->toast($e->getMessage(), 'error');

        return;
    }

    unset($this->scheduled);
    $this->load();
    $this->dispatch('date-sheet-saved');
    $this->toast(__('Date sheet saved.'));
};

?>

<div class="card mb-0">
    <div class="card-header d-flex flex-wrap align-items-end gap-3">
        <div style="min-width: 14rem">
            <label class="form-label" for="sheet-class">{{ __('Class') }}</label>
            <select id="sheet-class" class="form-select" wire:model.live="class_id">
                @foreach ($this->classes as $class)
                    <option value="{{ $class->id }}">{{ $class->name }}{{ isset($this->scheduled[$class->id]) ? ' ✓' : '' }}</option>
                @endforeach
            </select>
        </div>
        @if ($this->canEdit && $this->subjects->isNotEmpty())
            <div class="d-flex align-items-end gap-2 ms-auto">
                <div style="width: 6rem">
                    <label class="form-label fs-13" for="default-max">{{ __('Total') }}</label>
                    <input type="number" id="default-max" class="form-control form-control-sm" wire:model="defaultMax" min="1">
                </div>
                <div style="width: 6rem">
                    <label class="form-label fs-13" for="default-pass">{{ __('Pass') }}</label>
                    <input type="number" id="default-pass" class="form-control form-control-sm" wire:model="defaultPass" min="0">
                </div>
                <button type="button" class="btn btn-sm btn-light" wire:click="applyDefaults">{{ __('Apply to all') }}</button>
            </div>
        @endif
    </div>

    @if ($this->classes->isEmpty())
        <x-empty-state icon="ri-building-2-line" :title="__('No classes yet')" />
    @elseif ($this->subjects->isEmpty())
        <x-empty-state icon="ri-book-2-line" :title="__('No subjects for this class')"
                       :message="__('Allocate subjects to the class for this academic year first (Academic Setup → Subject Allocation).')" />
    @else
        <form wire:submit="save">
            <fieldset @disabled(! $this->canEdit)>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light bg-opacity-30">
                            <tr>
                                <th scope="col" class="ps-4">{{ __('Subject') }}</th>
                                <th scope="col">{{ __('Date') }}</th>
                                <th scope="col">{{ __('From') }}</th>
                                <th scope="col">{{ __('To') }}</th>
                                <th scope="col">{{ __('Room') }}</th>
                                <th scope="col" style="width: 6.5rem">{{ __('Total') }}</th>
                                <th scope="col" class="pe-4" style="width: 6.5rem">{{ __('Pass') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->subjects as $subject)
                                @php($on = $rows[$subject->id]['on'] ?? false)
                                <tr wire:key="sheet-{{ $class_id }}-{{ $subject->id }}" @class(['opacity-50' => ! $on])>
                                    <td class="ps-4">
                                        <div class="form-check mb-0">
                                            <input type="checkbox" class="form-check-input" id="subject-{{ $subject->id }}" wire:model.live="rows.{{ $subject->id }}.on">
                                            <label class="form-check-label fw-medium" for="subject-{{ $subject->id }}">{{ $subject->name }}</label>
                                            <small class="d-block text-muted">{{ $subject->code }}</small>
                                        </div>
                                    </td>
                                    <td>
                                        <input type="date" @class(['form-control form-control-sm', 'is-invalid' => $errors->has("rows.{$subject->id}.exam_date")]) wire:model="rows.{{ $subject->id }}.exam_date"
                                               min="{{ $this->exam->start_date->toDateString() }}" max="{{ $this->exam->end_date->toDateString() }}" @disabled(! $on) aria-label="{{ __('Date') }}">
                                        @error("rows.{$subject->id}.exam_date") <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </td>
                                    <td><input type="time" class="form-control form-control-sm" wire:model="rows.{{ $subject->id }}.start_time" @disabled(! $on) aria-label="{{ __('Starts') }}"></td>
                                    <td><input type="time" class="form-control form-control-sm" wire:model="rows.{{ $subject->id }}.end_time" @disabled(! $on) aria-label="{{ __('Ends') }}"></td>
                                    <td>
                                        <select class="form-select form-select-sm" wire:model="rows.{{ $subject->id }}.classroom_id" @disabled(! $on) aria-label="{{ __('Room') }}">
                                            <option value="">—</option>
                                            @foreach ($this->rooms as $id => $label)
                                                <option value="{{ $id }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" min="1" step="0.5" @class(['form-control form-control-sm', 'is-invalid' => $errors->has("rows.{$subject->id}.max_marks")])
                                               wire:model="rows.{{ $subject->id }}.max_marks" @disabled(! $on) aria-label="{{ __('Total marks') }}">
                                    </td>
                                    <td class="pe-4">
                                        <input type="number" min="0" step="0.5" @class(['form-control form-control-sm', 'is-invalid' => $errors->has("rows.{$subject->id}.passing_marks")])
                                               wire:model="rows.{{ $subject->id }}.passing_marks" @disabled(! $on) aria-label="{{ __('Passing marks') }}">
                                        @error("rows.{$subject->id}.passing_marks") <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($this->canEdit)
                    <div class="card-footer d-flex flex-wrap align-items-center gap-3">
                        <small class="text-muted flex-grow-1">{{ __('Unticked subjects aren\'t examined. A subject with marks entered can\'t be removed.') }}</small>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save"><i class="ri-save-line me-1"></i>{{ __('Save date sheet') }}</span>
                            <span wire:loading wire:target="save"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Saving…') }}</span>
                        </button>
                    </div>
                @endif
            </fieldset>
        </form>
    @endif
</div>
