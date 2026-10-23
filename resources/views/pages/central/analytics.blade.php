<?php

use App\Enums\SchoolStatus;
use App\Enums\StudentStatus;
use App\Enums\SubscriptionState;
use App\Models\Plan;
use App\Models\School;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\SubscriptionPayment;
use App\Support\Billing\Money;

use function Livewire\Volt\{computed, layout, title};

layout('layouts.super-admin');
title(__('Analytics'));

/** The last twelve months, oldest first: 'Y-m' => 'M Y'. */
$months = computed(function () {
    $months = [];

    for ($i = 11; $i >= 0; $i--) {
        $month = today()->startOfMonth()->subMonthsNoOverflow($i);
        $months[$month->format('Y-m')] = $month->format('M y');
    }

    return $months;
});

$revenueByMonth = computed(function () {
    $totals = SubscriptionPayment::query()
        ->where('paid_on', '>=', today()->startOfMonth()->subMonthsNoOverflow(11))
        ->selectRaw("DATE_FORMAT(paid_on, '%Y-%m') as month, SUM(amount) as total")
        ->groupBy('month')
        ->pluck('total', 'month');

    return array_map(fn (string $month) => round((float) ($totals[$month] ?? 0), 2), array_keys($this->months));
});

$newSchoolsByMonth = computed(function () {
    $counts = School::query()
        ->withTrashed()
        ->where('created_at', '>=', today()->startOfMonth()->subMonthsNoOverflow(11))
        ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as total")
        ->groupBy('month')
        ->pluck('total', 'month');

    return array_map(fn (string $month) => (int) ($counts[$month] ?? 0), array_keys($this->months));
});

$byPlan = computed(fn () => Plan::query()->withTrashed()->withCount('schools')->ordered()->get()
    ->filter(fn (Plan $plan) => $plan->schools_count > 0)
    ->mapWithKeys(fn (Plan $plan) => [$plan->name => $plan->schools_count])
    ->all());

$byState = computed(function () {
    $counts = [];

    foreach (SubscriptionState::cases() as $state) {
        $counts[$state->label()] = School::query()->where('status', SchoolStatus::Active)->whereSubscriptionState($state)->count();
    }

    $counts[__('Suspended')] = School::query()->where('status', SchoolStatus::Suspended)->count();

    return array_filter($counts);
});

$kpis = computed(function () {
    // Normalised to a month: a yearly plan contributes a twelfth of its price.
    $mrr = School::query()->with('plan')->where('status', SchoolStatus::Active)
        ->whereSubscriptionState(SubscriptionState::Active)->get()
        ->sum(fn (School $school) => $school->plan ? (float) $school->plan->price / $school->plan->billing_cycle->months() : 0);

    $schools = School::query()->count();
    $students = StudentProfile::query()->where('status', StudentStatus::Active)->count();

    return [
        'mrr' => $mrr,
        'revenue' => array_sum($this->revenueByMonth),
        'schools' => $schools,
        'active' => School::query()->where('status', SchoolStatus::Active)->count(),
        'trials' => School::query()->where('status', SchoolStatus::Active)->whereSubscriptionState(SubscriptionState::Trial)->count(),
        'students' => $students,
        'staff' => StaffProfile::query()->count(),
        'perSchool' => $schools > 0 ? (int) round($students / $schools) : 0,
    ];
});

$largest = computed(fn () => School::query()
    ->with('plan')
    ->withCount(['activeStudents as students_count', 'staff'])
    ->orderByDesc('students_count')
    ->limit(8)
    ->get());

?>

@php
    $kpis = $this->kpis;
    $currency = Money::currency();
@endphp

<div>
    <x-page-header :title="__('Analytics')" :breadcrumbs="[__('Super Admin') => route('central.dashboard'), __('Analytics') => null]" />

    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Monthly recurring revenue')" :value="Money::platform($kpis['mrr'])" icon="ri-repeat-line" color="success"
                                                    :hint="__('From schools on a paid period')" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Revenue, last 12 months')" :value="Money::platform($kpis['revenue'])" icon="ri-funds-line" color="primary" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Active schools')" :value="number_format($kpis['active'])" icon="ri-building-4-line" color="info"
                                                    :hint="trans_choice('{0} No trials|{1} :count on trial|[2,*] :count on trial', $kpis['trials'], ['count' => $kpis['trials']])" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Active students')" :value="number_format($kpis['students'])" icon="ri-graduation-cap-line" color="warning"
                                                    :hint="__(':count per school on average · :staff staff', ['count' => number_format($kpis['perSchool']), 'staff' => number_format($kpis['staff'])])" /></div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="card h-100 mb-0">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('Revenue by month (:currency)', ['currency' => $currency]) }}</h5></div>
                <div class="card-body">
                    <x-chart height="300" :options="[
                        'chart' => ['type' => 'bar'],
                        'series' => [['name' => __('Revenue'), 'data' => $this->revenueByMonth]],
                        'xaxis' => ['categories' => array_values($this->months)],
                        'plotOptions' => ['bar' => ['borderRadius' => 4, 'columnWidth' => '45%']],
                    ]" />
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card h-100 mb-0">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('Schools by subscription') }}</h5></div>
                <div class="card-body">
                    @if ($this->byState)
                        <x-chart height="300" :options="[
                            'chart' => ['type' => 'donut'],
                            'series' => array_values($this->byState),
                            'labels' => array_keys($this->byState),
                        ]" />
                    @else
                        <x-empty-state icon="ri-pie-chart-line" :title="__('No schools yet')" />
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="card h-100 mb-0">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('New schools by month') }}</h5></div>
                <div class="card-body">
                    <x-chart height="280" :options="[
                        'chart' => ['type' => 'area'],
                        'series' => [['name' => __('New schools'), 'data' => $this->newSchoolsByMonth]],
                        'xaxis' => ['categories' => array_values($this->months)],
                        'yaxis' => ['min' => 0, 'forceNiceScale' => true, 'decimalsInFloat' => 0],
                        'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => 0.35, 'opacityTo' => 0.05]],
                    ]" />
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card h-100 mb-0">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('Schools by plan') }}</h5></div>
                <div class="card-body">
                    @if ($this->byPlan)
                        <x-chart height="280" :options="[
                            'chart' => ['type' => 'pie'],
                            'series' => array_values($this->byPlan),
                            'labels' => array_keys($this->byPlan),
                        ]" />
                    @else
                        <x-empty-state icon="ri-price-tag-3-line" :title="__('No schools on a plan')" />
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-0">
        <div class="card-header"><h5 class="card-title mb-0">{{ __('Largest schools') }}</h5></div>
        @if ($this->largest->isEmpty())
            <x-empty-state icon="ri-building-4-line" :title="__('No schools yet')" />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle table-nowrap mb-0">
                    <thead class="bg-light bg-opacity-30">
                        <tr>
                            <th scope="col">{{ __('School') }}</th>
                            <th scope="col" class="d-none d-md-table-cell">{{ __('Plan') }}</th>
                            <th scope="col" style="min-width: 12rem">{{ __('Students') }}</th>
                            <th scope="col" class="d-none d-md-table-cell text-end">{{ __('Staff') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->largest as $school)
                            <tr wire:key="largest-{{ $school->id }}">
                                <td><a href="{{ route('central.schools.show', $school) }}" wire:navigate class="fw-medium">{{ $school->name }}</a></td>
                                <td class="d-none d-md-table-cell">{{ $school->plan?->name ?? '—' }}</td>
                                <td><x-usage-meter :label="__('Active')" :used="$school->students_count" :limit="$school->plan?->max_students" /></td>
                                <td class="d-none d-md-table-cell text-end">{{ number_format($school->staff_count) }}</td>
                                <td><span class="badge bg-{{ $school->status->color() }}-subtle text-{{ $school->status->color() }}">{{ $school->status->label() }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
