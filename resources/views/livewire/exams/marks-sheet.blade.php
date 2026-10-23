<?php

/*
 * Marks entry for one subject of an examination in one section.
 *     <livewire:exams.marks-sheet :examination="3" sheet="14-2" :key="..." />
 *
 * Grades update as you type (with the exam's grade scale) and are worked
 * out again on the server when saving. Enter moves to the next student.
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Examination;
use App\Services\Exams\Gradebook;
use App\Services\Exams\Grading;
use App\Tables\ActionFailed;

use function Livewire\Volt\{computed, mount, protect, state, uses};

uses([InteractsWithUi::class]);

state(['examination' => null, 'sheet' => null])->locked();

// student id => ['marks' => '45', 'absent' => false, 'remarks' => '']
state(['entries' => []]);

$gradebook = protect(fn (): Gradebook => app(Gradebook::class));

$exam = computed(fn () => Examination::query()->findOrFail($this->examination));

$info = computed(fn () => $this->gradebook()->sheetsFor(auth()->user(), $this->exam)->get($this->sheet));

$roster = computed(fn () => $this->gradebook()->roster($this->exam, $this->info['class'], $this->info['section'], $this->info['schedule']));

$canEdit = computed(fn () => $this->gradebook()->canEdit(auth()->user(), $this->exam));

// [{grade, min}] from the highest band down, for grading in the browser.
$bands = computed(fn () => app(Grading::class)->scaleFor($this->exam)?->items
    ->map(fn ($item) => ['grade' => $item->grade, 'min' => (float) $item->min_percentage])
    ->sortByDesc('min')->values()->all() ?? []);

mount(function (int $examination, string $sheet) {
    $this->examination = $examination;
    $this->sheet = $sheet;

    abort_unless($this->info, 403);

    $this->entries = $this->gradebook()->entries($this->info['schedule'], $this->roster);
});

$save = function () {
    abort_unless($this->canEdit && $this->info, 403);

    $max = (float) $this->info['schedule']->max_marks;

    $this->validate([
        'entries.*.marks' => ['nullable', 'numeric', 'min:0', 'max:'.$max],
        'entries.*.absent' => ['boolean'],
        'entries.*.remarks' => ['nullable', 'string', 'max:255'],
    ], [
        'entries.*.marks.max' => __('Out of :max.', ['max' => Gradebook::trim($max)]),
    ], [
        'entries.*.marks' => __('marks'),
    ]);

    try {
        $count = $this->gradebook()->save($this->exam, $this->info['schedule'], $this->info['section'], $this->entries, auth()->user());
    } catch (ActionFailed $e) {
        $this->toast($e->getMessage(), 'error');

        return;
    }

    $this->entries = $this->gradebook()->entries($this->info['schedule'], $this->roster);
    $this->toast(trans_choice('{0} Marks cleared.|{1} Marks saved for :count student.|[2,*] Marks saved for :count students.', $count, ['count' => $count]));
};

?>

@php
    $schedule = $this->info['schedule'];
    $max = (float) $schedule->max_marks;
    $pass = (float) $schedule->passing_marks;
    $format = tenant()->setting('date_format');
@endphp

<div class="card mb-0"
     x-data="{
        bands: @js($this->bands),
        max: {{ $max }},
        pass: {{ $pass }},
        grade(marks, absent) {
            if (absent) return this.bands.length ? this.bands[this.bands.length - 1].grade : '';
            if (marks === '' || marks === null || isNaN(marks)) return '';
            const pct = Number(marks) / this.max * 100;
            const band = this.bands.find(b => pct >= b.min);
            return band ? band.grade : '';
        },
        next(el) {
            const inputs = [...$root.querySelectorAll('.marks-input:not([disabled])')];
            inputs[inputs.indexOf(el) + 1]?.focus();
        },
     }">
    <div class="card-header d-flex flex-wrap align-items-center gap-3">
        <div class="flex-grow-1">
            <h5 class="card-title mb-0">{{ $this->info['label'] }}</h5>
            <small class="text-muted">
                {{ __('Out of :max · pass :pass', ['max' => Gradebook::trim($max), 'pass' => Gradebook::trim($pass)]) }}
                @if ($schedule->exam_date) · {{ $schedule->exam_date->format($format) }} @endif
            </small>
        </div>
        @if ($this->exam->isPublished())
            <span class="badge bg-success-subtle text-success"><i class="ri-lock-line me-1"></i>{{ __('Results published') }}</span>
        @elseif (! $this->canEdit)
            <span class="badge bg-light text-muted border"><i class="ri-eye-line me-1"></i>{{ __('View only') }}</span>
        @endif
    </div>

    @if ($this->roster->isEmpty())
        <x-empty-state icon="ri-group-line" :title="__('No students in this section')" />
    @else
        <form wire:submit="save">
            <fieldset @disabled(! $this->canEdit)>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col" class="ps-4" style="width: 4.5rem">{{ __('Roll') }}</th>
                                <th scope="col">{{ __('Student') }}</th>
                                <th scope="col" style="width: 8rem">{{ __('Marks') }}</th>
                                <th scope="col" style="width: 6rem">{{ __('Absent') }}</th>
                                <th scope="col" class="text-center" style="width: 5rem">{{ __('Grade') }}</th>
                                <th scope="col" class="pe-4 d-none d-md-table-cell">{{ __('Remarks') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->roster as $enrollment)
                                @php($id = $enrollment->student_profile_id)
                                <tr wire:key="marks-{{ $id }}">
                                    <td class="ps-4 text-muted">{{ $enrollment->roll_number ?? '—' }}</td>
                                    <td>
                                        <span class="fw-medium">{{ $enrollment->student?->user?->name }}</span>
                                        <small class="d-block text-muted">{{ $enrollment->student?->admission_number }}</small>
                                    </td>
                                    <td>
                                        <input type="number" step="0.5" min="0" max="{{ $max }}" inputmode="decimal"
                                               wire:model="entries.{{ $id }}.marks" x-bind:disabled="$wire.entries[{{ $id }}].absent"
                                               x-on:keydown.enter.prevent="next($el)"
                                               aria-label="{{ __('Marks for :name', ['name' => $enrollment->student?->user?->name]) }}"
                                               x-bind:class="Number($wire.entries[{{ $id }}].marks) < pass && $wire.entries[{{ $id }}].marks !== '' ? 'text-danger' : ''"
                                               @class(['form-control form-control-sm marks-input', 'is-invalid' => $errors->has("entries.{$id}.marks")])>
                                        @error("entries.{$id}.marks") <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </td>
                                    <td>
                                        <div class="form-check form-switch mb-0">
                                            <input type="checkbox" role="switch" class="form-check-input" id="absent-{{ $id }}" wire:model="entries.{{ $id }}.absent"
                                                   x-on:change="if ($el.checked) $wire.entries[{{ $id }}].marks = ''">
                                            <label class="form-check-label visually-hidden" for="absent-{{ $id }}">{{ __('Absent') }}</label>
                                        </div>
                                    </td>
                                    <td class="text-center fw-semibold" x-text="grade($wire.entries[{{ $id }}].marks, $wire.entries[{{ $id }}].absent)"></td>
                                    <td class="pe-4 d-none d-md-table-cell">
                                        <input type="text" class="form-control form-control-sm" maxlength="255" wire:model="entries.{{ $id }}.remarks"
                                               aria-label="{{ __('Remarks for :name', ['name' => $enrollment->student?->user?->name]) }}">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($this->canEdit)
                    <div class="card-footer d-flex flex-wrap align-items-center gap-3">
                        <small class="text-muted flex-grow-1">{{ __('Leave marks blank to clear them. Press Enter to move to the next student.') }}</small>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save"><i class="ri-save-line me-1"></i>{{ __('Save marks') }}</span>
                            <span wire:loading wire:target="save"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Saving…') }}</span>
                        </button>
                    </div>
                @endif
            </fieldset>
        </form>
    @endif
</div>
