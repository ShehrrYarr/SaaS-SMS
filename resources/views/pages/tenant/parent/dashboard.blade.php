<?php

/*
 * Parent portal dashboard: every child at a glance, then progress cards for
 * the child picked in the header switcher — attendance, fee dues, results
 * and report cards, teacher remarks, homework and today's timetable.
 */

use App\Services\Portals\StudentOverview;
use App\Support\Billing\Money;
use App\Support\SelfService;

use function Livewire\Volt\{computed, layout, title};

layout('layouts.app');
title(__('Dashboard'));

$children = computed(fn () => SelfService::children(auth()->user()));

$student = computed(fn () => SelfService::student());

// One line per child: attendance rate and what is owed.
$strip = computed(function () {
    $service = app(StudentOverview::class);
    $fees = StudentOverview::enabled('invoices.view');

    return $this->children->map(fn ($child) => [
        'child' => $child,
        'rate' => $service->attendance($child)['rate'],
        'due' => $fees ? $service->fees($child)['outstanding'] : null,
    ]);
});

$overview = computed(function () {
    $student = $this->student;

    if (! $student) {
        return null;
    }

    $service = app(StudentOverview::class);
    $results = StudentOverview::enabled('examinations.view');

    return [
        'attendance' => $service->attendance($student),
        'today' => $service->today($student),
        'homework' => StudentOverview::enabled('homework.view') ? $service->upcomingHomework($student) : null,
        'exams' => $results ? $service->publishedExams($student) : null,
        'latest' => $results ? $service->latestResult($student) : null,
        'fees' => StudentOverview::enabled('invoices.view') ? $service->fees($student) : null,
        'remarks' => $service->remarks($student),
    ];
});

$switchChild = function (int $child) {
    abort_unless(SelfService::select(auth()->user(), $child), 404);

    $this->redirect(route('parent.dashboard'), navigate: true);
};

?>

@php($data = $this->overview)

<div>
    <x-page-header :title="__('Dashboard')" :breadcrumbs="[__('Parent') => null, __('Dashboard') => null]" />

    <div class="row g-4 mb-4">
        <div class="col-xl-4"><x-dashboard.welcome /></div>
        <div class="col-xl-8">
            <div class="card mb-0 h-100">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('My children') }}</h5></div>
                @if ($this->children->isEmpty())
                    <x-empty-state icon="ri-parent-line" :title="__('No children linked yet')" :message="__('Ask the school office to link your children to your account.')" class="py-6" />
                @else
                    <div class="card-body">
                        <div class="row g-3">
                            @foreach ($this->strip as $row)
                                @php($child = $row['child'])
                                @php($viewing = $child->id === $this->student?->id)
                                <div class="col-md-6" wire:key="child-{{ $child->id }}">
                                    <div @class(['border rounded p-3 h-100 d-flex align-items-center gap-3', 'border-primary bg-primary-subtle' => $viewing])>
                                        <span class="avatar-item avatar avatar-title bg-info-subtle text-info fw-semibold flex-shrink-0">{{ $child->user?->initials() }}</span>
                                        <div class="min-w-0 flex-grow-1">
                                            <span class="fw-semibold d-block text-truncate">{{ $child->user?->name }}</span>
                                            <small class="text-muted d-block">{{ $child->placementLabel() ?? __('Not placed in a class yet') }}</small>
                                            <small class="d-block">
                                                <i class="ri-calendar-check-line text-success"></i> {{ $row['rate'] !== null ? $row['rate'].'%' : '—' }}
                                                @if ($row['due'] !== null)
                                                    <span class="ms-2 {{ $row['due'] > 0 ? 'text-warning' : 'text-muted' }}"><i class="ri-wallet-3-line"></i> {{ $row['due'] > 0 ? __(':amount due', ['amount' => Money::school($row['due'])]) : __('Paid up') }}</span>
                                                @endif
                                            </small>
                                        </div>
                                        @if ($viewing)
                                            <span class="badge bg-primary">{{ __('Viewing') }}</span>
                                        @else
                                            <button type="button" class="btn btn-sm btn-light" wire:click="switchChild({{ $child->id }})">{{ __('View') }}</button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if ($data)
        <h5 class="mb-3">{{ __(':name\'s progress', ['name' => $this->student->user?->name]) }}</h5>

        <div class="row g-4 mb-4">
            <div class="col-xl-4"><x-family.attendance-card :stats="$data['attendance']" :url="route('parent.attendance')" /></div>
            @if ($data['fees'] !== null)
                <div class="col-xl-4"><x-family.fees-card :summary="$data['fees']" portal="parent" /></div>
            @endif
            @if ($data['exams'] !== null)
                <div class="col-xl-4"><x-family.results-card :card="$data['latest']" :exams="$data['exams']" portal="parent" :student="$this->student" /></div>
            @endif
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-4"><x-family.remarks-card :remarks="$data['remarks']" /></div>
            @if ($data['homework'] !== null)
                <div class="col-xl-4"><x-family.homework-card :items="$data['homework']" portal="parent" /></div>
            @endif
            <div class="col-xl-4"><x-timetable.today :entries="$data['today']" :title="__('Today\'s classes')" /></div>
        </div>
    @endif

    <x-notices.latest />
</div>
