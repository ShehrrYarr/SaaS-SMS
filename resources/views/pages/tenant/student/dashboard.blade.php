<?php

/*
 * Student portal dashboard: today's timetable, homework to do, attendance,
 * the latest result and fee dues at a glance.
 */

use App\Services\Portals\StudentOverview;
use App\Support\SelfService;

use function Livewire\Volt\{computed, layout, title};

layout('layouts.app');
title(__('Dashboard'));

$student = computed(fn () => SelfService::student());

$overview = computed(function () {
    $student = $this->student;

    if (! $student) {
        return null;
    }

    $service = app(StudentOverview::class);
    $homework = StudentOverview::enabled('homework.view');
    $results = StudentOverview::enabled('examinations.view');

    return [
        'attendance' => $service->attendance($student),
        'today' => $service->today($student),
        'homework' => $homework ? $service->upcomingHomework($student) : null,
        'toDo' => $homework ? $service->homeworkToDoCount($student) : null,
        'exams' => $results ? $service->publishedExams($student) : null,
        'latest' => $results ? $service->latestResult($student) : null,
        'fees' => StudentOverview::enabled('invoices.view') ? $service->fees($student) : null,
        'remarks' => $service->remarks($student),
        // Null when the plan leaves the service out; ['current' => null] when the student doesn't use it.
        'transport' => StudentOverview::enabled('transport.view') ? ['current' => $service->transport($student)] : null,
        'hostel' => StudentOverview::enabled('hostel.view') ? ['current' => $service->hostel($student)] : null,
        'library' => StudentOverview::enabled('library.view') ? $service->library($student->user, 0) : null,
    ];
});

?>

@php($data = $this->overview)

<div>
    <x-page-header :title="__('Dashboard')" :breadcrumbs="[__('Student') => null, __('Dashboard') => null]" />

    @if (! $this->student)
        <div class="row g-4 mb-4">
            <div class="col-xl-6"><x-dashboard.welcome /></div>
        </div>
        <x-family.missing />
    @else
        <div class="row g-4 mb-4">
            <div class="col-xl-6">
                <x-dashboard.welcome :subtitle="collect([$this->student->placementLabel(), __('Admission no. :number', ['number' => $this->student->admission_number])])->filter()->implode(' · ')" />
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-stat-card :label="__('Attendance')" :value="$data['attendance']['rate'] !== null ? $data['attendance']['rate'].'%' : '—'"
                             icon="ri-calendar-check-line" color="success" :hint="__('this year')" />
            </div>
            <div class="col-sm-6 col-xl-3">
                @if ($data['toDo'] !== null)
                    <x-stat-card :label="__('Homework to do')" :value="$data['toDo']" icon="ri-book-open-line" :color="$data['toDo'] > 0 ? 'warning' : 'primary'" />
                @else
                    <x-stat-card :label="__('Classes today')" :value="$data['today']->count()" icon="ri-time-line" color="primary" />
                @endif
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-4"><x-timetable.today :entries="$data['today']" /></div>
            @if ($data['homework'] !== null)
                <div class="col-xl-4"><x-family.homework-card :items="$data['homework']" portal="student" /></div>
            @endif
            <div class="col-xl-4"><x-family.attendance-card :stats="$data['attendance']" :url="route('student.attendance')" /></div>
        </div>

        <div class="row g-4 mb-4">
            @if ($data['exams'] !== null)
                <div class="col-xl-4"><x-family.results-card :card="$data['latest']" :exams="$data['exams']" portal="student" :student="$this->student" /></div>
            @endif
            @if ($data['fees'] !== null)
                <div class="col-xl-4"><x-family.fees-card :summary="$data['fees']" portal="student" /></div>
            @endif
            <div class="col-xl-4"><x-family.remarks-card :remarks="$data['remarks']" /></div>
        </div>

        @if ($data['transport'] || $data['hostel'] || $data['library'])
            <div class="row g-4 mb-4">
                <div class="col-xl-4"><x-family.services-card :transport="$data['transport']" :hostel="$data['hostel']" :library="$data['library']" portal="student" /></div>
            </div>
        @endif
    @endif

    <x-notices.latest />
</div>
