<?php

/*
 * Student and parent portals: published examination results — subject marks,
 * grade, position, the class teacher's remarks — and the report card to view
 * or download.
 */

use App\Services\Exams\ResultSheet;
use App\Services\Homework\Homeworks;
use App\Services\Portals\StudentOverview;
use App\Support\Portal;
use App\Support\SelfService;

use function Livewire\Volt\{computed, layout, mount, state, title};

layout('layouts.app');
title(__('Results'));

state(['exam' => ''])->url();

$student = computed(fn () => SelfService::student());

$exams = computed(fn () => $this->student ? app(StudentOverview::class)->publishedExams($this->student) : collect());

$current = computed(fn () => $this->exams->firstWhere('id', (int) $this->exam) ?? $this->exams->first());

$card = computed(fn () => $this->current ? app(ResultSheet::class)->forStudent($this->current, $this->student) : null);

mount(function () {
    $this->exam = (string) ($this->current?->id ?? '');
});

?>

@php
    $portal = Portal::current();
    $card = $this->card;
    $result = $card['result'] ?? null;
@endphp

<div>
    <x-page-header :title="__('Results')" :breadcrumbs="SelfService::breadcrumbs([__('Results') => null])" />

    @if (! $this->student)
        <x-family.missing />
    @elseif ($this->exams->isEmpty())
        <div class="card mb-0">
            <x-empty-state icon="ri-award-line" :title="__('No results yet')" :message="__('Results appear here once the school publishes them.')" />
        </div>
    @else
        <div class="row g-4">
            <div class="col-lg-4 col-xl-3">
                <div class="card mb-0">
                    <div class="card-header"><h5 class="card-title mb-0">{{ __('Examinations') }}</h5></div>
                    <div class="list-group list-group-flush">
                        @foreach ($this->exams as $exam)
                            <button type="button" wire:key="exam-{{ $exam->id }}" wire:click="$set('exam', '{{ $exam->id }}')"
                                    @class(['list-group-item list-group-item-action', 'active' => $exam->id === $this->current?->id])
                                    @if ($exam->id === $this->current?->id) aria-current="true" @endif>
                                <span class="d-block fw-medium">{{ $exam->name }}</span>
                                <small @class(['text-muted' => $exam->id !== $this->current?->id])>{{ collect([$exam->term?->name, $exam->academicYear?->name])->filter()->implode(' · ') }}</small>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="col-lg-8 col-xl-9">
                @if (! $card)
                    <div class="card mb-0">
                        <x-empty-state icon="ri-award-line" :title="__('No result for this examination')" />
                    </div>
                @else
                    <div class="card mb-4">
                        <div class="card-header d-flex flex-wrap align-items-center gap-2">
                            <div class="flex-grow-1">
                                <h5 class="card-title mb-0">{{ $this->current->name }}</h5>
                                <small class="text-muted">{{ $card['class']->name }}{{ $card['section'] ? ' · '.$card['section']->name : '' }}</small>
                            </div>
                            <a href="{{ route($portal.'.report-card', ['examination' => $this->current->id, 'student' => $this->student->id]) }}" target="_blank" class="btn btn-sm btn-light">
                                <i class="ri-printer-line me-1"></i>{{ __('Report card') }}
                            </a>
                            <a href="{{ route($portal.'.report-card.pdf', ['examination' => $this->current->id, 'student' => $this->student->id]) }}" class="btn btn-sm btn-primary">
                                <i class="ri-download-2-line me-1"></i>{{ __('Download PDF') }}
                            </a>
                        </div>
                        <div class="card-body">
                            <div class="row g-3 text-center">
                                <div class="col-6 col-md-3">
                                    <div class="border rounded p-3 h-100">
                                        <small class="text-muted d-block">{{ __('Percentage') }}</small>
                                        <span class="fs-4 fw-semibold">{{ $result['percentage'] !== null ? number_format($result['percentage'], 2).'%' : '—' }}</span>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="border rounded p-3 h-100">
                                        <small class="text-muted d-block">{{ __('Grade') }}</small>
                                        <span class="fs-4 fw-semibold">{{ $result['grade'] ?? '—' }}</span>
                                        @if ($result['grade_remark'])<small class="d-block text-muted">{{ $result['grade_remark'] }}</small>@endif
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="border rounded p-3 h-100">
                                        <small class="text-muted d-block">{{ __('Position') }}</small>
                                        <span class="fs-4 fw-semibold">{{ $result['rank'] ?? '—' }}</span>
                                        <small class="d-block text-muted">{{ __('of :count', ['count' => $card['section_size']]) }}</small>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="border rounded p-3 h-100">
                                        <small class="text-muted d-block">{{ __('Result') }}</small>
                                        @if ($result['passed'] === null)
                                            <span class="fs-5 fw-semibold text-muted">{{ __('Incomplete') }}</span>
                                        @else
                                            <span @class(['fs-4 fw-semibold', 'text-success' => $result['passed'], 'text-danger' => ! $result['passed']])>{{ $result['passed'] ? __('Pass') : __('Fail') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="bg-light bg-opacity-30">
                                    <tr>
                                        <th class="ps-4">{{ __('Subject') }}</th>
                                        <th class="text-end">{{ __('Marks') }}</th>
                                        <th class="text-center">{{ __('Grade') }}</th>
                                        <th class="pe-4 d-none d-md-table-cell">{{ __('Remarks') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($card['subjects'] as $subject)
                                        <tr>
                                            <td class="ps-4 fw-medium">{{ $subject['subject'] }}</td>
                                            <td class="text-end text-nowrap">
                                                @if ($subject['absent'])
                                                    <span class="badge bg-danger-subtle text-danger">{{ __('Absent') }}</span>
                                                @else
                                                    <span @class(['fw-semibold', 'text-danger' => $subject['passed'] === false])>{{ $subject['obtained'] !== null ? Homeworks::number($subject['obtained']) : '—' }}</span>
                                                    <span class="text-muted"> / {{ Homeworks::number($subject['max']) }}</span>
                                                @endif
                                            </td>
                                            <td class="text-center">{{ $subject['grade'] ?? '—' }}</td>
                                            <td class="pe-4 d-none d-md-table-cell text-muted fs-13">{{ $subject['remarks'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="fw-semibold">
                                        <td class="ps-4">{{ __('Total') }}</td>
                                        <td class="text-end">{{ Homeworks::number($result['obtained']) }} <span class="text-muted fw-normal">/ {{ Homeworks::number($result['max']) }}</span></td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="card mb-0 h-100">
                                <div class="card-header"><h5 class="card-title mb-0">{{ __('Class teacher\'s remarks') }}</h5></div>
                                <div class="card-body">
                                    @if ($card['remarks'])
                                        <p class="mb-2 text-pre-line">{{ $card['remarks'] }}</p>
                                        @if ($card['class_teacher'])<small class="text-muted">— {{ $card['class_teacher'] }}</small>@endif
                                    @else
                                        <p class="text-muted mb-0">{{ __('No remarks for this examination.') }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card mb-0 h-100">
                                <div class="card-header"><h5 class="card-title mb-0">{{ __('Attendance this term') }}</h5></div>
                                <div class="card-body">
                                    @php($attendance = $card['attendance'])
                                    @if ($attendance['marked'] > 0)
                                        <span class="fs-4 fw-semibold">{{ $attendance['rate'] !== null ? $attendance['rate'].'%' : '—' }}</span>
                                        <p class="mb-0"><x-family.attendance-line :present="$attendance['present']" :school-days="$attendance['marked'] - $attendance['excused']" :excused="$attendance['excused']" class="text-muted fs-13" /></p>
                                    @else
                                        <p class="text-muted mb-0">{{ __('No attendance marked for this period.') }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
