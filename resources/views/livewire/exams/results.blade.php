<?php

/*
 * An examination's result sheet for a class or section: marks per subject,
 * total, percentage, grade, result and position. Exports to CSV and links
 * to report cards.
 *     <livewire:exams.results :examination="$exam->id" />
 */

use App\Models\ExamSchedule;
use App\Models\Examination;
use App\Models\SchoolClass;
use App\Services\Exams\Gradebook;
use App\Services\Exams\ResultSheet;
use App\Services\Imports\CsvFile;
use Illuminate\Support\Str;

use function Livewire\Volt\{computed, mount, on, state, updated};

state(['examination' => null])->locked();
state(['class_id' => '', 'section_id' => '']);

$exam = computed(fn () => Examination::query()->findOrFail($this->examination));

// Classes that sit this examination.
$classes = computed(fn () => SchoolClass::query()->ordered()
    ->with(['sections' => fn ($query) => $query->ordered()])
    ->whereIn('id', ExamSchedule::query()->where('examination_id', $this->examination)->select('class_id'))
    ->get());

$class = computed(fn () => $this->classes->firstWhere('id', (int) $this->class_id));

$sheet = computed(fn () => $this->class
    ? app(ResultSheet::class)->forClass($this->exam, $this->class, $this->class->sections->firstWhere('id', (int) $this->section_id))
    : null);

mount(function (int $examination) {
    $this->authorize('examinations.view');
    $this->examination = Examination::query()->findOrFail($examination)->id;
    $this->class_id = (string) ($this->classes->first()?->id ?? '');
});

updated(['class_id' => fn () => $this->section_id = '']);

on(['date-sheet-saved' => function () {
    unset($this->classes, $this->sheet);
}]);

$export = function () {
    $sheet = $this->sheet;
    abort_unless($sheet, 404);

    $headers = [__('Position'), __('Roll'), __('Admission no.'), __('Student'), __('Section'),
        ...$sheet['schedules']->map(fn ($schedule) => $schedule->subject?->name.' ('.Gradebook::trim($schedule->max_marks).')')->all(),
        __('Total'), __('Out of'), '%', __('Grade'), __('Result')];

    $rows = $sheet['rows']->map(fn (array $row) => [
        $row['rank'],
        $row['enrollment']->roll_number,
        $row['student']?->admission_number,
        $row['student']?->user?->name,
        $row['enrollment']->section?->name,
        ...$sheet['schedules']->map(function ($schedule) use ($row) {
            $mark = $row['marks']->get($schedule->id);

            return $mark?->is_absent ? 'ABS' : ($mark?->marks_obtained !== null ? Gradebook::trim($mark->marks_obtained) : '');
        })->all(),
        Gradebook::trim($row['obtained']),
        Gradebook::trim($row['max']),
        $row['percentage'],
        $row['grade'],
        $row['passed'] === null ? __('Incomplete') : ($row['passed'] ? __('Pass') : __('Fail')),
    ]);

    $csv = CsvFile::write($headers, $rows);
    $name = Str::slug($this->exam->name.' '.$this->class->name.' '.($this->class->sections->firstWhere('id', (int) $this->section_id)?->name ?? '')).'-results.csv';

    return response()->streamDownload(fn () => print ($csv), $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
};

?>

@php
    $sheet = $this->sheet;
    $rows = $sheet ? $sheet['rows']->sortBy(fn ($row) => [$row['rank'] ?? PHP_INT_MAX, mb_strtolower((string) $row['student']?->user?->name)]) : collect();
    $complete = $rows->where('complete', true);
@endphp

<div class="card mb-0">
    <div class="card-header d-flex flex-wrap align-items-end gap-3">
        @if ($this->classes->isNotEmpty())
            <div style="min-width: 12rem">
                <label class="form-label" for="results-class">{{ __('Class') }}</label>
                <select id="results-class" class="form-select" wire:model.live="class_id">
                    @foreach ($this->classes as $class)
                        <option value="{{ $class->id }}">{{ $class->name }}</option>
                    @endforeach
                </select>
            </div>
            @if ($this->class?->sections->isNotEmpty())
                <div style="min-width: 10rem">
                    <label class="form-label" for="results-section">{{ __('Section') }}</label>
                    <select id="results-section" class="form-select" wire:model.live="section_id">
                        <option value="">{{ __('Whole class') }}</option>
                        @foreach ($this->class->sections as $section)
                            <option value="{{ $section->id }}">{{ $section->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if ($rows->isNotEmpty())
                <div class="d-flex flex-wrap gap-2 ms-auto">
                    <button type="button" class="btn btn-light" wire:click="export"><i class="ri-download-2-line me-1"></i>{{ __('Export CSV') }}</button>
                    @if (Route::has('admin.examinations.report-cards'))
                        <a href="{{ route('admin.examinations.report-cards', ['examination' => $examination, 'class' => $class_id, 'section' => $section_id ?: null]) }}" target="_blank" rel="noopener" class="btn btn-primary">
                            <i class="ri-file-pdf-2-line me-1"></i>{{ __('Report cards (PDF)') }}
                        </a>
                    @endif
                </div>
            @endif
        @endif
    </div>

    @if ($this->classes->isEmpty())
        <x-empty-state icon="ri-file-list-3-line" :title="__('No date sheet yet')" :message="__('Results appear once classes have subjects on the date sheet.')" />
    @elseif ($rows->isEmpty())
        <x-empty-state icon="ri-group-line" :title="__('No students')" :message="__('Students enrolled in this class for the examination\'s year appear here.')" />
    @else
        <div class="d-flex flex-wrap gap-3 px-4 pt-3 fs-13 text-muted">
            <span>{{ trans_choice(':count student|:count students', $rows->count(), ['count' => $rows->count()]) }}</span>
            <span>{{ __(':count with all marks', ['count' => $complete->count()]) }}</span>
            @if ($complete->isNotEmpty())
                <span class="text-success">{{ __(':count passed', ['count' => $complete->where('passed', true)->count()]) }}</span>
                <span class="text-danger">{{ __(':count failed', ['count' => $complete->where('passed', false)->count()]) }}</span>
                <span>{{ __('Class average :avg%', ['avg' => round($complete->avg('percentage'), 1)]) }}</span>
            @endif
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 text-nowrap">
                <thead class="bg-light bg-opacity-30">
                    <tr>
                        <th scope="col" class="ps-4">#</th>
                        <th scope="col">{{ __('Student') }}</th>
                        @foreach ($sheet['schedules'] as $schedule)
                            <th scope="col" class="text-center" title="{{ __('Out of :max, pass :pass', ['max' => Gradebook::trim($schedule->max_marks), 'pass' => Gradebook::trim($schedule->passing_marks)]) }}">
                                {{ $schedule->subject?->code ?: Str::limit($schedule->subject?->name, 10) }}
                                <small class="d-block fw-normal text-muted">/{{ Gradebook::trim($schedule->max_marks) }}</small>
                            </th>
                        @endforeach
                        <th scope="col" class="text-center">{{ __('Total') }}</th>
                        <th scope="col" class="text-center">%</th>
                        <th scope="col" class="text-center">{{ __('Grade') }}</th>
                        <th scope="col">{{ __('Result') }}</th>
                        <th scope="col" class="pe-4 text-end"><span class="visually-hidden">{{ __('Report card') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr wire:key="result-{{ $row['student']->id }}">
                            <td class="ps-4 fw-semibold">{{ $row['rank'] ?? '—' }}</td>
                            <td>
                                <span class="fw-medium">{{ $row['student']->user?->name }}</span>
                                <small class="d-block text-muted">{{ collect([$row['enrollment']->section?->name, $row['enrollment']->roll_number ? __('Roll :roll', ['roll' => $row['enrollment']->roll_number]) : null])->filter()->implode(' · ') }}</small>
                            </td>
                            @foreach ($sheet['schedules'] as $schedule)
                                @php($mark = $row['marks']->get($schedule->id))
                                <td class="text-center">
                                    @if ($mark?->is_absent)
                                        <span class="badge bg-danger-subtle text-danger">{{ __('ABS') }}</span>
                                    @elseif ($mark?->marks_obtained !== null)
                                        <span @class(['text-danger fw-semibold' => (float) $mark->marks_obtained < (float) $schedule->passing_marks])>{{ Gradebook::trim($mark->marks_obtained) }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="text-center fw-medium">{{ Gradebook::trim($row['obtained']) }}</td>
                            <td class="text-center">{{ $row['percentage'] !== null ? $row['percentage'] : '—' }}</td>
                            <td class="text-center fw-semibold">{{ $row['grade'] ?? '—' }}</td>
                            <td>
                                @if ($row['passed'] === null)
                                    <span class="badge bg-light text-muted border">{{ __('Incomplete') }}</span>
                                @elseif ($row['passed'])
                                    <span class="badge bg-success-subtle text-success">{{ __('Pass') }}</span>
                                @else
                                    <span class="badge bg-danger-subtle text-danger" title="{{ implode(', ', $row['failed']) }}">{{ __('Fail') }}</span>
                                @endif
                            </td>
                            <td class="pe-4 text-end">
                                @if (Route::has('admin.examinations.report-card'))
                                    <a href="{{ route('admin.examinations.report-card', ['examination' => $examination, 'student' => $row['student']->id]) }}" target="_blank" rel="noopener"
                                       class="btn btn-sm btn-subtle-primary" title="{{ __('Report card') }}"><i class="ri-file-list-2-line"></i><span class="visually-hidden">{{ __('Report card') }}</span></a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
