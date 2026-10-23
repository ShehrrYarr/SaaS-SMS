<?php

/*
 * HR: the daily staff register (status, check-in and check-out).
 */

use App\Enums\StaffAttendanceStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Department;
use App\Services\Attendance\SchoolCalendar;
use App\Services\Attendance\StaffAttendanceRegister;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;

use function Livewire\Volt\{computed, layout, mount, protect, state, title, updated, uses};

layout('layouts.app');
title(__('Staff attendance'));

uses([InteractsWithUi::class]);

state(['date' => '', 'department' => '']);

// staff id => ['status', 'check_in', 'check_out', 'remarks']
state(['entries' => []]);

$day = protect(fn (): ?Carbon => preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) ? Carbon::parse($this->date) : null);

$staff = computed(fn () => app(StaffAttendanceRegister::class)->roster($this->department ? (int) $this->department : null));

$flags = computed(fn () => $this->day()
    ? collect(app(StaffAttendanceRegister::class)->entries($this->staff, $this->day()))->map(fn ($entry) => ['saved' => $entry['saved'], 'on_leave' => $entry['on_leave']])->all()
    : []);

$departments = computed(fn () => Department::query()->orderBy('name')->pluck('name', 'id')->all());

$canMark = computed(fn () => auth()->user()->can('staff-attendance.mark') && $this->day() && ! $this->day()->gt(tenant()->today()));

$loadEntries = protect(function () {
    unset($this->staff, $this->flags);

    $this->entries = $this->day()
        ? collect(app(StaffAttendanceRegister::class)->entries($this->staff, $this->day()))
            ->map(fn ($entry) => collect($entry)->only(['status', 'check_in', 'check_out', 'remarks'])->all())
            ->all()
        : [];
});

mount(function () {
    $this->authorize('staff-attendance.view');
    $this->date = tenant()->today()->toDateString();
    $this->loadEntries();
});

updated([
    'date' => fn () => $this->loadEntries(),
    'department' => fn () => $this->loadEntries(),
]);

$markAll = function (string $status) {
    if (! StaffAttendanceStatus::tryFrom($status) || ! $this->canMark) {
        return;
    }

    foreach ($this->entries as $id => $entry) {
        if (! ($this->flags[$id]['on_leave'] ?? false)) {
            $this->entries[$id]['status'] = $status;
        }
    }
};

$save = function (StaffAttendanceRegister $register) {
    $this->authorize('staff-attendance.mark');
    abort_unless($this->canMark, 403);

    $this->validate([
        'entries.*.status' => ['required', 'in:'.implode(',', StaffAttendanceStatus::values())],
        'entries.*.check_in' => ['nullable', 'date_format:H:i'],
        'entries.*.check_out' => ['nullable', 'date_format:H:i'],
        'entries.*.remarks' => ['nullable', 'string', 'max:255'],
    ], [], ['entries.*.check_in' => __('check-in'), 'entries.*.check_out' => __('check-out')]);

    try {
        $counts = $register->save($this->day(), $this->entries, auth()->user(), $this->department ? (int) $this->department : null);
    } catch (ActionFailed $e) {
        $this->toast($e->getMessage(), 'error');

        return;
    }

    unset($this->flags);
    $this->toast(__('Staff register saved: :present present, :absent absent.', ['present' => $counts['present'] + $counts['late'] + $counts['half_day'], 'absent' => $counts['absent']]));
};

?>

@php
    $school = tenant();
    $dayDate = $this->day();
    $flags = $this->flags;
    $savedCount = collect($flags)->where('saved', true)->count();
    $statuses = StaffAttendanceStatus::cases();
@endphp

<div>
    <x-page-header :title="__('Staff attendance')" :breadcrumbs="[__('HR') => route('hr.dashboard'), __('Staff attendance') => null]" />

    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap align-items-end gap-3">
            <div>
                <label class="form-label" for="staff-date">{{ __('Date') }}</label>
                <input type="date" id="staff-date" class="form-control" wire:model.live="date" max="{{ $school->today()->toDateString() }}">
            </div>
            @if ($this->departments)
                <div style="min-width: 14rem">
                    <label class="form-label" for="staff-department">{{ __('Department') }}</label>
                    <select id="staff-department" class="form-select" wire:model.live="department">
                        <option value="">{{ __('All departments') }}</option>
                        @foreach ($this->departments as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if ($date !== $school->today()->toDateString())
                <button type="button" class="btn btn-light" wire:click="$set('date', '{{ $school->today()->toDateString() }}')">{{ __('Today') }}</button>
            @endif
        </div>
    </div>

    <div class="card mb-0"
         x-data="{ counts: {}, recount() { const c = {}; $el.querySelectorAll('input.staff-status:checked').forEach(i => c[i.value] = (c[i.value] || 0) + 1); this.counts = c } }"
         x-init="recount()" x-on:change="recount()" x-effect="$wire.entries; $nextTick(() => recount())">
        <div class="card-header d-flex flex-wrap align-items-center gap-3">
            <div class="flex-grow-1">
                <h5 class="card-title mb-0">{{ $dayDate ? $dayDate->translatedFormat('l').', '.$dayDate->format($school->setting('date_format')) : __('Choose a date') }}</h5>
                <small class="text-muted">{{ $savedCount ? __(':saved of :total saved', ['saved' => $savedCount, 'total' => count($flags)]) : __('Not taken yet') }}</small>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @foreach ($statuses as $status)
                    <span class="badge bg-{{ $status->color() }}-subtle text-{{ $status->color() }} fs-12">{{ $status->label() }}: <span x-text="counts['{{ $status->value }}'] || 0">0</span></span>
                @endforeach
            </div>
        </div>

        @if ($dayDate && app(SchoolCalendar::class)->isWeeklyOff($dayDate))
            <div class="alert alert-warning rounded-0 border-start-0 border-end-0 mb-0 py-2 fs-13"><i class="ri-calendar-event-line me-1"></i>{{ __('This is a weekly off day for the school.') }}</div>
        @endif

        @if ($this->staff->isEmpty())
            <x-empty-state icon="ri-user-star-line" :title="__('No staff')" :message="__('Staff with an active account appear here.')" />
        @else
            <form wire:submit="save">
                <fieldset @disabled(! $this->canMark)>
                    @if ($this->canMark)
                        <div class="d-flex flex-wrap align-items-center gap-2 px-4 pt-3">
                            <span class="text-muted fs-13">{{ __('Mark everyone') }}</span>
                            <button type="button" class="btn btn-sm btn-subtle-success" wire:click="markAll('present')">{{ StaffAttendanceStatus::Present->label() }}</button>
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col" class="ps-4">{{ __('Staff member') }}</th>
                                    <th scope="col">{{ __('Status') }}</th>
                                    <th scope="col" style="width: 8rem">{{ __('In') }}</th>
                                    <th scope="col" style="width: 8rem">{{ __('Out') }}</th>
                                    <th scope="col" class="pe-4 d-none d-lg-table-cell">{{ __('Remarks') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->staff as $member)
                                    <tr wire:key="staff-row-{{ $member->id }}">
                                        <td class="ps-4">
                                            <span class="fw-medium">{{ $member->user?->name }}</span>
                                            @if ($flags[$member->id]['on_leave'] ?? false)
                                                <span class="badge bg-secondary-subtle text-secondary ms-1">{{ __('On leave') }}</span>
                                            @endif
                                            <small class="d-block text-muted">{{ collect([$member->employee_id, $member->designation])->filter()->implode(' · ') }}</small>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-1" role="radiogroup" aria-label="{{ __('Attendance for :name', ['name' => $member->user?->name]) }}">
                                                @foreach ($statuses as $status)
                                                    <input type="radio" class="btn-check staff-status" id="staff-{{ $member->id }}-{{ $status->value }}" name="staff-{{ $member->id }}"
                                                           value="{{ $status->value }}" wire:model="entries.{{ $member->id }}.status" autocomplete="off">
                                                    <label class="btn btn-sm btn-outline-{{ $status->color() }}" for="staff-{{ $member->id }}-{{ $status->value }}" title="{{ $status->label() }}">{{ $status->label() }}</label>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td>
                                            <input type="time" @class(['form-control form-control-sm', 'is-invalid' => $errors->has("entries.{$member->id}.check_in")]) wire:model="entries.{{ $member->id }}.check_in" aria-label="{{ __('Check-in') }}">
                                        </td>
                                        <td>
                                            <input type="time" @class(['form-control form-control-sm', 'is-invalid' => $errors->has("entries.{$member->id}.check_out")]) wire:model="entries.{{ $member->id }}.check_out" aria-label="{{ __('Check-out') }}">
                                        </td>
                                        <td class="pe-4 d-none d-lg-table-cell">
                                            <input type="text" class="form-control form-control-sm" maxlength="255" wire:model="entries.{{ $member->id }}.remarks" aria-label="{{ __('Remarks') }}">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($this->canMark)
                        <div class="card-footer d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                                <span wire:loading.remove wire:target="save"><i class="ri-save-line me-1"></i>{{ __('Save register') }}</span>
                                <span wire:loading wire:target="save"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Saving…') }}</span>
                            </button>
                        </div>
                    @endif
                </fieldset>
            </form>
        @endif
    </div>
</div>
