<?php

/*
 * Monthly attendance sheet for a section: students down, days across, with
 * totals. Shared by the School Admin and Teacher portals; teachers see their
 * own sections.
 */

use App\Enums\AttendanceStatus;
use App\Models\StudentAttendance;
use App\Services\Attendance\SchoolCalendar;
use App\Services\Attendance\StudentAttendanceRegister;
use App\Services\Imports\CsvFile;
use App\Support\Portal;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;

use function Livewire\Volt\{computed, layout, mount, protect, state, title};

layout('layouts.app');
title(__('Attendance report'));

state(['portal' => 'admin'])->locked();
state(['group' => '', 'month' => '']);

$groups = computed(fn () => app(StudentAttendanceRegister::class)->groupsFor(auth()->user()));

mount(function () {
    $this->authorize('attendance.view');

    $this->portal = Portal::current() === 'teacher' ? 'teacher' : 'admin';
    $this->month = tenant()->today()->format('Y-m');
    $this->group = (string) ($this->groups->keys()->first() ?? '');
});

$period = protect(function (): ?array {
    if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)) {
        return null;
    }

    $start = Carbon::createFromFormat('!Y-m', $this->month)->startOfMonth();

    return [$start, $start->copy()->endOfMonth()];
});

$sheet = computed(function () {
    $item = $this->groups->get($this->group);
    $period = $this->period();

    if (! $item || ! $period) {
        return null;
    }

    [$start, $end] = $period;
    $register = app(StudentAttendanceRegister::class);
    $calendar = app(SchoolCalendar::class);
    $students = $register->roster($item['class'], $item['section']);

    $marks = StudentAttendance::query()
        ->whereIn('student_profile_id', $students->modelKeys())
        ->whereDate('date', '>=', $start)
        ->whereDate('date', '<=', $end)
        ->get(['student_profile_id', 'date', 'status'])
        ->groupBy('student_profile_id')
        ->map(fn ($records) => $records->mapWithKeys(fn ($record) => [$record->date->day => $record->status]));

    $days = collect(CarbonPeriod::create($start, $end))->map(fn ($day) => [
        'day' => $day->day,
        'weekday' => $day->translatedFormat('D'),
        'off' => $calendar->isWeeklyOff($day),
        'future' => $day->gt(tenant()->today()),
    ]);

    $rows = $students->map(function ($student) use ($marks) {
        $mine = $marks->get($student->id, collect());
        $counts = $mine->countBy(fn (AttendanceStatus $status) => $status->value);
        $marked = $mine->count();
        $attended = ($counts[AttendanceStatus::Present->value] ?? 0) + ($counts[AttendanceStatus::Late->value] ?? 0);

        return [
            'student' => $student,
            'marks' => $mine,
            'counts' => $counts,
            'rate' => $marked ? round($attended / $marked * 100) : null,
        ];
    });

    return ['label' => $item['label'], 'start' => $start, 'days' => $days, 'rows' => $rows];
});

$export = function () {
    $sheet = $this->sheet;
    abort_unless($sheet, 404);

    $headers = [__('Roll'), __('Student'), __('Admission no.'), ...$sheet['days']->pluck('day')->all(), ...array_map(fn ($status) => $status->label(), AttendanceStatus::cases()), '%'];

    $rows = $sheet['rows']->map(fn ($row) => [
        $row['student']->roll_number,
        $row['student']->user?->name,
        $row['student']->admission_number,
        ...$sheet['days']->map(fn ($day) => isset($row['marks'][$day['day']]) ? strtoupper(substr($row['marks'][$day['day']]->value, 0, 1)) : '')->all(),
        ...array_map(fn ($status) => $row['counts'][$status->value] ?? 0, AttendanceStatus::cases()),
        $row['rate'] ?? '',
    ]);

    $csv = CsvFile::write($headers, $rows);
    $name = Str::slug($sheet['label']).'-attendance-'.$this->month.'.csv';

    return response()->streamDownload(fn () => print ($csv), $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
};

?>

@php
    $sheet = $this->sheet;
    $letters = collect(AttendanceStatus::cases())->mapWithKeys(fn ($status) => [$status->value => [Str::upper(Str::substr($status->label(), 0, 1)), $status->color()]]);
@endphp

<div>
    <x-page-header :title="__('Attendance report')"
                   :breadcrumbs="[Portal::label($portal) => route(Portal::dashboardRoute($portal)), __('Attendance') => Route::has($portal.'.attendance.index') ? route($portal.'.attendance.index') : null, __('Monthly report') => null]">
        @if ($sheet)
            <x-slot:actions>
                <button type="button" class="btn btn-light" wire:click="export"><i class="ri-download-2-line me-1"></i>{{ __('Export CSV') }}</button>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if ($this->groups->isEmpty())
        <div class="card mb-0">
            <x-empty-state icon="ri-table-line" :title="__('No sections to report on')" :message="__('You see the sections you are class teacher of or teach a subject in.')" />
        </div>
    @else
        <div class="card mb-4">
            <div class="card-body d-flex flex-wrap align-items-end gap-3">
                <div class="flex-grow-1" style="max-width: 22rem">
                    <label class="form-label" for="report-group">{{ __('Section') }}</label>
                    <select id="report-group" class="form-select" wire:model.live="group">
                        @foreach ($this->groups as $key => $item)
                            <option value="{{ $key }}">{{ $item['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label" for="report-month">{{ __('Month') }}</label>
                    <input type="month" id="report-month" class="form-control" wire:model.live="month" max="{{ tenant()->today()->format('Y-m') }}">
                </div>
                <div class="d-flex flex-wrap gap-2 ms-auto fs-13">
                    @foreach ($letters as [$letter, $color])
                        <span class="badge bg-{{ $color }}-subtle text-{{ $color }}">{{ $letter }} = {{ AttendanceStatus::cases()[$loop->index]->label() }}</span>
                    @endforeach
                </div>
            </div>
        </div>

        @if ($sheet)
            <div class="card mb-0">
                <div class="card-header"><h5 class="card-title mb-0">{{ $sheet['label'] }} · {{ $sheet['start']->translatedFormat('F Y') }}</h5></div>
                @if ($sheet['rows']->isEmpty())
                    <x-empty-state icon="ri-group-line" :title="__('No students in this section')" />
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0 attendance-sheet">
                            <thead class="bg-light bg-opacity-30">
                                <tr>
                                    <th scope="col" class="ps-3 sticky-col">{{ __('Student') }}</th>
                                    @foreach ($sheet['days'] as $day)
                                        <th scope="col" @class(['text-center px-1', 'bg-light text-muted' => $day['off']])>
                                            <span class="d-block">{{ $day['day'] }}</span><small class="fw-normal text-muted">{{ Str::substr($day['weekday'], 0, 2) }}</small>
                                        </th>
                                    @endforeach
                                    @foreach ($letters as [$letter, $color])
                                        <th scope="col" class="text-center text-{{ $color }}">{{ $letter }}</th>
                                    @endforeach
                                    <th scope="col" class="text-center pe-3">%</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sheet['rows'] as $row)
                                    <tr>
                                        <th scope="row" class="ps-3 fw-medium text-nowrap sticky-col">
                                            {{ $row['student']->user?->name }}
                                            @if ($row['student']->roll_number)<small class="text-muted ms-1">#{{ $row['student']->roll_number }}</small>@endif
                                        </th>
                                        @foreach ($sheet['days'] as $day)
                                            @php($status = $row['marks'][$day['day']] ?? null)
                                            <td @class(['text-center px-1', 'bg-light' => $day['off']])>
                                                @if ($status)
                                                    <span class="fw-semibold text-{{ $letters[$status->value][1] }}" title="{{ $status->label() }}">{{ $letters[$status->value][0] }}</span>
                                                @endif
                                            </td>
                                        @endforeach
                                        @foreach ($letters as $value => [$letter, $color])
                                            <td class="text-center">{{ $row['counts'][$value] ?? 0 }}</td>
                                        @endforeach
                                        <td class="text-center pe-3 fw-medium">{{ $row['rate'] !== null ? $row['rate'].'%' : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    @endif
</div>
