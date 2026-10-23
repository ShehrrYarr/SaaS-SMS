<?php

/*
 * One day's attendance register for a class section.
 *     <livewire:attendance.student-register group-key="4-12" date="2026-10-23" :key="..." />
 *
 * Opens read-only when the user may only view it (another teacher's section,
 * or a past day without attendance.manage).
 */

use App\Enums\AttendanceStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Services\Attendance\SchoolCalendar;
use App\Services\Attendance\StudentAttendanceRegister;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;

use function Livewire\Volt\{computed, mount, protect, state, uses};

uses([InteractsWithUi::class]);

state(['groupKey' => null, 'date' => null])->locked();

// student id => ['status' => 'present', 'remarks' => '']
state(['entries' => []]);

$register = protect(fn (): StudentAttendanceRegister => app(StudentAttendanceRegister::class));

$day = protect(fn (): Carbon => Carbon::parse($this->date));

mount(function (string $groupKey, string $date) {
    abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 404);

    $this->groupKey = $groupKey;
    $this->date = $date;

    abort_unless($this->group, 403);

    $this->loadEntries();
});

$group = computed(fn () => $this->register()->group(auth()->user(), $this->groupKey));

$students = computed(fn () => $this->register()->roster($this->group['class'], $this->group['section']));

// Saved / on-leave flags per student.
$flags = computed(fn () => collect($this->register()->entries($this->students, $this->day()))
    ->map(fn (array $entry) => ['saved' => $entry['saved'], 'on_leave' => $entry['on_leave']])
    ->all());

$canMark = computed(fn () => $this->register()->canMark(auth()->user(), $this->groupKey, $this->day()));

$isWeeklyOff = computed(fn () => app(SchoolCalendar::class)->isWeeklyOff($this->day()));

$loadEntries = protect(function () {
    $this->entries = collect($this->register()->entries($this->students, $this->day()))
        ->map(fn (array $entry) => ['status' => $entry['status'], 'remarks' => $entry['remarks']])
        ->all();
});

$markAll = function (string $status) {
    if (! AttendanceStatus::tryFrom($status) || ! $this->canMark) {
        return;
    }

    foreach ($this->entries as $id => $entry) {
        // Students on approved leave stay excused.
        if (! ($this->flags[$id]['on_leave'] ?? false)) {
            $this->entries[$id]['status'] = $status;
        }
    }
};

$save = function () {
    abort_unless($this->canMark, 403);

    $this->validate([
        'entries' => ['array'],
        'entries.*.status' => ['required', 'in:'.implode(',', AttendanceStatus::values())],
        'entries.*.remarks' => ['nullable', 'string', 'max:255'],
    ]);

    try {
        $counts = $this->register()->save($this->group['class'], $this->group['section'], $this->day(), $this->entries, auth()->user());
    } catch (ActionFailed $e) {
        $this->toast($e->getMessage(), 'error');

        return;
    }

    unset($this->flags);
    $this->dispatch('attendance-saved');
    $this->toast(__('Attendance saved: :present present, :absent absent, :late late, :excused excused.', $counts));
};

?>

@php
    $school = tenant();
    $day = Carbon::parse($date);
    $flags = $this->flags;
    $savedCount = collect($flags)->where('saved', true)->count();
    $statuses = AttendanceStatus::cases();
@endphp

<div class="card mb-0"
     x-data="{ counts: {}, recount() { const c = {}; $el.querySelectorAll('input.attendance-status:checked').forEach(i => c[i.value] = (c[i.value] || 0) + 1); this.counts = c } }"
     x-init="recount()" x-on:change="recount()" x-effect="$wire.entries; $nextTick(() => recount())">
    <div class="card-header d-flex flex-wrap align-items-center gap-3">
        <div class="flex-grow-1">
            <h5 class="card-title mb-0">{{ $this->group['label'] }}</h5>
            <small class="text-muted">{{ $day->translatedFormat('l') }}, {{ $day->format($school->setting('date_format')) }}
                · @if ($savedCount === 0) {{ __('Not taken yet') }} @elseif ($savedCount < count($flags)) {{ __(':saved of :total saved', ['saved' => $savedCount, 'total' => count($flags)]) }} @else {{ __('Saved') }} @endif
            </small>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @foreach ($statuses as $status)
                <span class="badge bg-{{ $status->color() }}-subtle text-{{ $status->color() }} fs-12">
                    {{ $status->label() }}: <span x-text="counts['{{ $status->value }}'] || 0">0</span>
                </span>
            @endforeach
        </div>
    </div>

    @if ($this->isWeeklyOff)
        <div class="alert alert-warning rounded-0 border-start-0 border-end-0 mb-0 py-2 fs-13">
            <i class="ri-calendar-event-line me-1"></i>{{ __('This is a weekly off day for the school.') }}
        </div>
    @endif

    @unless ($this->canMark)
        <div class="alert alert-light rounded-0 border-start-0 border-end-0 mb-0 py-2 fs-13">
            <i class="ri-eye-line me-1"></i>
            @if ($day->gt($school->today()))
                {{ __('Attendance can\'t be taken for a future date.') }}
            @else
                {{ __('View only. Teachers mark today\'s register for their own sections; earlier days are corrected by someone who manages attendance.') }}
            @endif
        </div>
    @endunless

    @if ($this->students->isEmpty())
        <x-empty-state icon="ri-group-line" :title="__('No students in this section')" :message="__('Active students placed in this class and section appear here.')" />
    @else
        <form wire:submit="save">
            <fieldset @disabled(! $this->canMark)>
                @if ($this->canMark)
                    <div class="d-flex flex-wrap align-items-center gap-2 px-4 pt-3">
                        <span class="text-muted fs-13">{{ __('Mark everyone') }}</span>
                        @foreach ([AttendanceStatus::Present, AttendanceStatus::Absent] as $status)
                            <button type="button" class="btn btn-sm btn-subtle-{{ $status->color() }}" wire:click="markAll('{{ $status->value }}')">{{ $status->label() }}</button>
                        @endforeach
                    </div>
                @endif

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col" class="ps-4" style="width: 4.5rem">{{ __('Roll') }}</th>
                                <th scope="col">{{ __('Student') }}</th>
                                <th scope="col">{{ __('Status') }}</th>
                                <th scope="col" class="pe-4 d-none d-md-table-cell">{{ __('Remarks') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->students as $student)
                                <tr wire:key="register-row-{{ $student->id }}">
                                    <td class="ps-4 text-muted">{{ $student->roll_number ?? '—' }}</td>
                                    <td>
                                        <span class="fw-medium">{{ $student->user?->name }}</span>
                                        @if ($flags[$student->id]['on_leave'] ?? false)
                                            <span class="badge bg-info-subtle text-info ms-1">{{ __('On leave') }}</span>
                                        @endif
                                        <small class="d-block text-muted">{{ $student->admission_number }}</small>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1" role="radiogroup" aria-label="{{ __('Attendance for :name', ['name' => $student->user?->name]) }}">
                                            @foreach ($statuses as $status)
                                                <input type="radio" class="btn-check attendance-status" id="att-{{ $student->id }}-{{ $status->value }}"
                                                       name="att-{{ $student->id }}" value="{{ $status->value }}" wire:model="entries.{{ $student->id }}.status" autocomplete="off">
                                                <label class="btn btn-sm btn-outline-{{ $status->color() }}" for="att-{{ $student->id }}-{{ $status->value }}"
                                                       title="{{ $status->label() }}">{{ Str::substr($status->label(), 0, 1) }}<span class="d-none d-xl-inline">{{ Str::substr($status->label(), 1) }}</span></label>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="pe-4 d-none d-md-table-cell">
                                        <input type="text" class="form-control form-control-sm" maxlength="255" wire:model="entries.{{ $student->id }}.remarks"
                                               aria-label="{{ __('Remarks for :name', ['name' => $student->user?->name]) }}">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($this->canMark)
                    <div class="card-footer d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save"><i class="ri-save-line me-1"></i>{{ __('Save attendance') }}</span>
                            <span wire:loading wire:target="save"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Saving…') }}</span>
                        </button>
                    </div>
                @endif
            </fieldset>
        </form>
    @endif
</div>
