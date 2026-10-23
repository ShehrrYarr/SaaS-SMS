<?php

/*
 * Teacher portal gradebook: pick an examination, then a subject sheet (or
 * report-card remarks for a class-teacher section) and enter marks.
 */

use App\Enums\ExamStatus;
use App\Models\Examination;
use App\Services\Exams\Gradebook;

use function Livewire\Volt\{computed, layout, mount, state, title, updated};

layout('layouts.app');
title(__('Gradebook'));

state(['exam' => '', 'sheet' => ''])->url();

$exams = computed(fn () => app(Gradebook::class)->exams());

$current = computed(fn () => $this->exams->firstWhere('id', (int) $this->exam));

$sheets = computed(fn () => $this->current ? app(Gradebook::class)->sheetsFor(auth()->user(), $this->current) : collect());

$remarkGroups = computed(fn () => $this->current ? app(Gradebook::class)->remarkGroupsFor(auth()->user(), $this->current) : collect());

mount(function () {
    $this->authorize('marks.edit');

    if (! $this->current) {
        // Start with the newest exam that is still open for marks.
        $open = $this->exams->first(fn (Examination $exam) => Gradebook::isOpen($exam)) ?? $this->exams->first();
        $this->exam = (string) ($open?->id ?? '');
    }

    if (! $this->sheets->has($this->sheet) && ! $this->remarkGroups->has($this->sheet)) {
        $this->sheet = (string) ($this->sheets->keys()->first() ?? '');
    }
});

updated(['exam' => function () {
    unset($this->current, $this->sheets, $this->remarkGroups);
    $this->sheet = (string) ($this->sheets->keys()->first() ?? '');
}]);

?>

@php($isRemarks = str_starts_with((string) $sheet, 'r-'))

<div>
    <x-page-header :title="__('Gradebook')" :breadcrumbs="[__('Teacher') => route('teacher.dashboard'), __('Gradebook') => null]" />

    @if ($this->exams->isEmpty())
        <div class="card mb-0">
            <x-empty-state icon="ri-file-edit-line" :title="__('No examinations open yet')"
                           :message="__('Examinations appear here once the school schedules them.')" />
        </div>
    @else
        <div class="card mb-4">
            <div class="card-body d-flex flex-wrap align-items-end gap-3">
                <div style="min-width: 16rem">
                    <label class="form-label" for="gradebook-exam">{{ __('Examination') }}</label>
                    <select id="gradebook-exam" class="form-select" wire:model.live="exam">
                        @foreach ($this->exams as $item)
                            <option value="{{ $item->id }}">{{ $item->name }} ({{ $item->academicYear?->name }}){{ $item->status === ExamStatus::Published ? ' · '.__('published') : '' }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($this->sheets->isNotEmpty() || $this->remarkGroups->isNotEmpty())
                    <div class="flex-grow-1" style="max-width: 26rem">
                        <label class="form-label" for="gradebook-sheet">{{ __('Sheet') }}</label>
                        <select id="gradebook-sheet" class="form-select" wire:model.live="sheet">
                            @if ($this->sheets->isNotEmpty())
                                <optgroup label="{{ __('Subject marks') }}">
                                    @foreach ($this->sheets as $key => $item)
                                        <option value="{{ $key }}">{{ $item['label'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                            @if ($this->remarkGroups->isNotEmpty())
                                <optgroup label="{{ __('Report card remarks') }}">
                                    @foreach ($this->remarkGroups as $key => $item)
                                        <option value="{{ $key }}">{{ __('Remarks') }} — {{ $item['label'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        </select>
                    </div>
                @endif
            </div>
        </div>

        @if ($this->sheets->isEmpty() && $this->remarkGroups->isEmpty())
            <div class="card mb-0">
                <x-empty-state icon="ri-book-2-line" :title="__('Nothing to mark in this examination')"
                               :message="__('You see the subjects you are allocated to teach, for classes on this examination\'s date sheet.')" />
            </div>
        @elseif ($isRemarks && $this->remarkGroups->has($sheet))
            <livewire:exams.remarks-sheet :examination="(int) $exam" :group="$sheet" :key="'remarks-'.$exam.'-'.$sheet" />
        @elseif ($this->sheets->has($sheet))
            <livewire:exams.marks-sheet :examination="(int) $exam" :sheet="$sheet" :key="'marks-'.$exam.'-'.$sheet" />
        @endif
    @endif
</div>
