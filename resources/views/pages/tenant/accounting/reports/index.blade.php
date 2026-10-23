<?php

/*
 * Finance reports: income, expenses and net for a period, by category and
 * payment method, the 12-month trend, what is outstanding and who is
 * overdue. CSV exports for the ledger and the overdue list.
 */

use App\Models\AcademicYear;
use App\Models\StudentProfile;
use App\Services\Finance\FinanceReport;
use App\Services\Imports\CsvFile;
use App\Support\Billing\Money;
use Illuminate\Support\Carbon;

use function Livewire\Volt\{computed, layout, mount, protect, state, title};

layout('layouts.app');
title(__('Finance reports'));

state(['range' => 'this_month', 'from' => '', 'to' => ''])->url();

mount(function () {
    $this->authorize('finance-reports.view');

    if (! array_key_exists($this->range, $this->ranges)) {
        $this->range = 'this_month';
    }
});

$ranges = computed(fn () => [
    'this_month' => __('This month'),
    'last_month' => __('Last month'),
    'this_year' => __('This academic year'),
    'custom' => __('Custom dates'),
]);

// [from, to] for the chosen range.
$period = protect(function (): array {
    $today = tenant()->today();

    return match ($this->range) {
        'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
        'this_year' => ($year = AcademicYear::query()->current()->first())
            ? [$year->start_date->copy(), $year->end_date->copy()->min($today)]
            : [$today->copy()->startOfYear(), $today->copy()],
        'custom' => [
            rescue(fn () => Carbon::parse($this->from), $today->copy()->startOfMonth(), false),
            rescue(fn () => Carbon::parse($this->to), $today->copy(), false),
        ],
        default => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
    };
});

$report = computed(function () {
    [$from, $to] = $this->period();
    $finance = app(FinanceReport::class);

    if ($to->lt($from)) {
        [$from, $to] = [$to, $from];
    }

    return [
        'from' => $from,
        'to' => $to,
        'summary' => $finance->summary($from, $to),
        'income' => $finance->incomeByCategory($from, $to),
        'expenses' => $finance->expensesByCategory($from, $to),
        'methods' => $finance->collectionByMethod($from, $to),
        'trend' => $finance->trend($to),
        'outstanding' => $finance->outstanding(),
    ];
});

$defaulters = computed(function () {
    $rows = app(FinanceReport::class)->defaulters(10);
    $students = StudentProfile::query()->with(['user', 'schoolClass', 'section'])->whereIn('id', $rows->pluck('student_profile_id'))->get()->keyBy('id');

    return $rows->map(fn ($row) => ['student' => $students->get($row->student_profile_id), 'invoices' => (int) $row->invoices, 'owed' => (float) $row->owed, 'oldest_due' => Carbon::parse($row->oldest_due)]);
});

$exportLedger = function () {
    $this->authorize('finance-reports.view');
    $report = $this->report;
    $format = tenant()->setting('date_format');

    $rows = app(FinanceReport::class)->entries($report['from'], $report['to'])->map(fn (array $entry) => [
        Carbon::parse($entry['date'])->format($format), $entry['ledger'], $entry['category'], $entry['description'], $entry['reference'], $entry['method'],
        number_format($entry['amount'], 2, '.', ''),
    ]);

    $csv = CsvFile::write([__('Date'), __('Ledger'), __('Category'), __('Description'), __('Reference'), __('Method'), __('Amount')], $rows);

    return response()->streamDownload(fn () => print ($csv), 'ledger-'.$report['from']->toDateString().'-to-'.$report['to']->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
};

$exportDefaulters = function () {
    $this->authorize('finance-reports.view');
    $rows = app(FinanceReport::class)->defaulters(5000);
    $students = StudentProfile::query()->with(['user', 'schoolClass', 'section', 'parents.user'])->whereIn('id', $rows->pluck('student_profile_id'))->get()->keyBy('id');
    $format = tenant()->setting('date_format');

    $csv = CsvFile::write(
        [__('Student'), __('Admission no.'), __('Class'), __('Parent'), __('Phone'), __('Overdue invoices'), __('Owed'), __('Oldest due date')],
        $rows->map(function ($row) use ($students, $format) {
            $student = $students->get($row->student_profile_id);
            $parent = $student?->parents->first();

            return [
                $student?->user?->name, $student?->admission_number, $student?->placementLabel(), $parent?->user?->name, $parent?->user?->phone ?? $student?->user?->phone,
                (int) $row->invoices, number_format((float) $row->owed, 2, '.', ''), Carbon::parse($row->oldest_due)->format($format),
            ];
        }),
    );

    return response()->streamDownload(fn () => print ($csv), 'overdue-fees-'.tenant()->today()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
};

?>

@php
    $report = $this->report;
    $summary = $report['summary'];
    $format = tenant()->setting('date_format');
    $trend = $report['trend'];
    $outstanding = $report['outstanding'];
@endphp

<div>
    <x-page-header :title="__('Finance reports')" :breadcrumbs="[__('Accounting') => route('accounting.dashboard'), __('Ledger') => null, __('Reports') => null]">
        <x-slot:actions>
            <button type="button" class="btn btn-light" wire:click="exportLedger" wire:loading.attr="disabled" wire:target="exportLedger">
                <i class="ri-download-2-line me-1"></i>{{ __('Export ledger (CSV)') }}
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="card-body d-flex flex-wrap align-items-end gap-3">
            <div>
                <label class="form-label" for="report-range">{{ __('Period') }}</label>
                <select id="report-range" class="form-select" wire:model.live="range">
                    @foreach ($this->ranges as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if ($range === 'custom')
                <div>
                    <label class="form-label" for="report-from">{{ __('From') }}</label>
                    <input type="date" id="report-from" class="form-control" wire:model.live.blur="from">
                </div>
                <div>
                    <label class="form-label" for="report-to">{{ __('To') }}</label>
                    <input type="date" id="report-to" class="form-control" wire:model.live.blur="to">
                </div>
            @endif
            <p class="text-muted mb-2 ms-auto">{{ $report['from']->format($format) }} – {{ $report['to']->format($format) }}</p>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Fees collected')" :value="Money::school($summary['fees'])" icon="ri-hand-coin-line" color="success" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Other income')" :value="Money::school($summary['other_income'])" icon="ri-funds-line" color="info" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Expenses')" :value="Money::school($summary['expenses'])" icon="ri-shopping-bag-3-line" color="danger" /></div>
        <div class="col-sm-6 col-xl-3">
            <x-stat-card :label="$summary['net'] >= 0 ? __('Surplus') : __('Deficit')" :value="Money::school($summary['net'])" icon="ri-scales-3-line" :color="$summary['net'] >= 0 ? 'primary' : 'warning'" />
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="card h-100 mb-0">
                <div class="card-header"><h6 class="card-title mb-0">{{ __('Income and expenses, last 12 months') }}</h6></div>
                <div class="card-body">
                    <x-chart wire:key="trend-{{ md5(json_encode($trend)) }}" height="300" :options="[
                        'chart' => ['type' => 'bar', 'toolbar' => ['show' => false]],
                        'series' => [['name' => __('Income'), 'data' => $trend['income']], ['name' => __('Expenses'), 'data' => $trend['expenses']]],
                        'xaxis' => ['categories' => $trend['labels']],
                        'colors' => ['#16a34a', '#dc2626'],
                        'plotOptions' => ['bar' => ['columnWidth' => '55%', 'borderRadius' => 3]],
                        'dataLabels' => ['enabled' => false],
                        'legend' => ['position' => 'top'],
                    ]" />
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card h-100 mb-0">
                <div class="card-header"><h6 class="card-title mb-0">{{ __('Fees by payment method') }}</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($report['methods'] as $method)
                        <li class="list-group-item d-flex justify-content-between">
                            <span>{{ $method['label'] }} <small class="text-muted">· {{ trans_choice(':count receipt|:count receipts', $method['count'], ['count' => $method['count']]) }}</small></span>
                            <span class="fw-medium">{{ Money::school($method['total']) }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-muted fs-13">{{ __('No fees received in this period.') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        @foreach ([[__('Income by category'), $report['income'], 'success'], [__('Expenses by category'), $report['expenses'], 'danger']] as [$heading, $rows, $color])
            <div class="col-lg-6">
                <div class="card h-100 mb-0">
                    <div class="card-header"><h6 class="card-title mb-0">{{ $heading }}</h6></div>
                    <div class="card-body">
                        @php($max = max($rows->max('total') ?? 0, 0.01))
                        @forelse ($rows as $row)
                            <div class="mb-3">
                                <div class="d-flex justify-content-between fs-13 mb-1"><span>{{ $row['name'] }}</span><span class="fw-medium">{{ Money::school($row['total']) }}</span></div>
                                <div class="progress" style="height: 6px"><div class="progress-bar bg-{{ $color }}" style="width: {{ round($row['total'] / $max * 100) }}%"></div></div>
                            </div>
                        @empty
                            <p class="text-muted fs-13 mb-0">{{ __('Nothing recorded in this period.') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card h-100 mb-0">
                <div class="card-header">
                    <h6 class="card-title mb-0">{{ __('Outstanding fees today') }}</h6>
                    <small class="text-muted">{{ trans_choice(':count unpaid invoice|:count unpaid invoices', $outstanding['invoices'], ['count' => $outstanding['invoices']]) }} · {{ __(':amount overdue', ['amount' => Money::school($outstanding['overdue'])]) }}</small>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>{{ __('Class') }}</th><th class="text-end">{{ __('Students') }}</th><th class="text-end">{{ __('Owed') }}</th></tr></thead>
                        <tbody>
                            @forelse ($outstanding['by_class'] as $row)
                                <tr><td>{{ $row['class'] }}</td><td class="text-end">{{ $row['students'] }}</td><td class="text-end">{{ Money::school($row['total']) }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="text-muted text-center py-3">{{ __('Nothing is owed.') }}</td></tr>
                            @endforelse
                        </tbody>
                        @if ($outstanding['by_class']->isNotEmpty())
                            <tfoot><tr class="fw-semibold"><td>{{ __('Total') }}</td><td></td><td class="text-end">{{ Money::school($outstanding['total']) }}</td></tr></tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card h-100 mb-0">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h6 class="card-title mb-0">{{ __('Largest overdue balances') }}</h6>
                    @if ($this->defaulters->isNotEmpty())
                        <button type="button" class="btn btn-sm btn-light" wire:click="exportDefaulters"><i class="ri-download-2-line me-1"></i>{{ __('Full list (CSV)') }}</button>
                    @endif
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>{{ __('Student') }}</th><th>{{ __('Overdue since') }}</th><th class="text-end">{{ __('Owed') }}</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($this->defaulters as $row)
                                <tr>
                                    <td>
                                        <span class="d-block">{{ $row['student']?->user?->name }}</span>
                                        <small class="text-muted">{{ collect([$row['student']?->admission_number, $row['student']?->placementLabel()])->filter()->implode(' · ') }}</small>
                                    </td>
                                    <td>{{ $row['oldest_due']->format($format) }} <small class="text-muted d-block">{{ trans_choice(':count invoice|:count invoices', $row['invoices'], ['count' => $row['invoices']]) }}</small></td>
                                    <td class="text-end fw-medium text-danger">{{ Money::school($row['owed']) }}</td>
                                    <td class="text-end">
                                        @can('fees.collect')
                                            @if ($row['student'])
                                                <a href="{{ route('accounting.collect', ['for' => $row['student']->id]) }}" wire:navigate class="btn btn-sm btn-subtle-success">{{ __('Collect') }}</a>
                                            @endif
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-muted text-center py-3">{{ __('No overdue fees.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
