<?php

/*
 * The school day: teaching periods and their times.
 *     $dispatch('open-modal', { component: 'forms.bell-schedule', size: 'lg' })
 *
 * Periods are renumbered in time order on save; timetable slots keep their
 * period number and move to its new times.
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Services\Timetable\BellSchedule;
use Illuminate\Support\Carbon;

use function Livewire\Volt\{computed, mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['existing' => 0])->locked();

state(['periods' => []]);

state(['first_start' => '08:00', 'minutes' => '40', 'count' => '8', 'break_after' => '4', 'break_minutes' => '20']);

mount(function () {
    $this->authorize('timetable.manage');

    $saved = app(BellSchedule::class)->periods();
    $this->existing = $saved->count();
    $this->periods = $saved->map(fn (array $period) => ['start' => $period['start'], 'end' => $period['end']])->all();

    if ($this->periods === []) {
        $this->generate();
    }
});

// Slots the save would clear because their period is gone.
$lost = computed(fn () => count($this->periods) < $this->existing ? app(BellSchedule::class)->slotsLostBy($this->periods) : 0);

$generate = function () {
    $this->validate([
        'first_start' => ['required', 'date_format:H:i'],
        'minutes' => ['required', 'integer', 'between:5,240'],
        'count' => ['required', 'integer', 'between:1,16'],
        'break_after' => ['nullable', 'integer', 'min:0', 'max:16'],
        'break_minutes' => ['nullable', 'integer', 'min:0', 'max:180'],
    ]);

    $this->periods = array_map(
        fn (array $period) => ['start' => $period['start'], 'end' => $period['end']],
        BellSchedule::generate($this->first_start, (int) $this->minutes, (int) $this->count, (int) $this->break_after, (int) $this->break_minutes),
    );
    $this->resetValidation();
};

$addPeriod = function () {
    $last = end($this->periods) ?: null;
    $start = $last && preg_match('/^\d{2}:\d{2}$/', (string) $last['end']) ? Carbon::parse($last['end']) : Carbon::parse('08:00');
    $length = $last && preg_match('/^\d{2}:\d{2}$/', (string) $last['start']) ? max(Carbon::parse($last['start'])->diffInMinutes(Carbon::parse($last['end']), false), 5) : 40;

    $this->periods[] = ['start' => $start->format('H:i'), 'end' => $start->copy()->addMinutes((int) $length)->format('H:i')];
};

$removePeriod = function (int $index) {
    unset($this->periods[$index]);
    $this->periods = array_values($this->periods);
};

rules([
    'periods' => ['required', 'array', 'min:1', 'max:16'],
    'periods.*.start' => ['required', 'date_format:H:i'],
    'periods.*.end' => ['required', 'date_format:H:i'],
])->messages([
    'periods.required' => __('Add at least one period.'),
    'periods.*.start.required' => __('Enter a start time.'),
    'periods.*.end.required' => __('Enter an end time.'),
    'periods.*.start.date_format' => __('Use a time like 08:00.'),
    'periods.*.end.date_format' => __('Use a time like 08:40.'),
]);

$save = function (BellSchedule $bells) {
    $this->authorize('timetable.manage');

    // In time order, so errors from the overlap check land on the right rows.
    $this->periods = collect($this->periods)->sortBy('start')->values()->all();

    $data = $this->validate();
    $cleared = $bells->save($data['periods']);

    $this->closeModal();
    $this->dispatch('timetable-updated');
    $this->toast($cleared
        ? trans_choice('School day saved. :count timetable slot was cleared.|School day saved. :count timetable slots were cleared.', $cleared, ['count' => $cleared])
        : __('School day saved.'));
};

?>

<x-modal.form :title="__('School day')" :subtitle="__('The teaching periods of a day. Gaps between periods show as breaks.')">
    <div class="border rounded p-3 mb-4 bg-light bg-opacity-50">
        <p class="fw-medium mb-2 fs-13"><i class="ri-magic-line me-1"></i>{{ __('Quick set-up') }}</p>
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2"><x-form.input name="first_start" type="time" :label="__('Starts at')" wrapper="mb-0" /></div>
            <div class="col-6 col-md-2"><x-form.input name="minutes" type="number" min="5" max="240" :label="__('Minutes')" wrapper="mb-0" /></div>
            <div class="col-4 col-md-2"><x-form.input name="count" type="number" min="1" max="16" :label="__('Periods')" wrapper="mb-0" /></div>
            <div class="col-4 col-md-2"><x-form.input name="break_after" type="number" min="0" max="16" :label="__('Break after')" wrapper="mb-0" /></div>
            <div class="col-4 col-md-2"><x-form.input name="break_minutes" type="number" min="0" max="180" :label="__('Break (min)')" wrapper="mb-0" /></div>
            <div class="col-12 col-md-2 d-grid">
                <button type="button" class="btn btn-light" wire:click="generate" wire:loading.attr="disabled" wire:target="generate">{{ __('Fill in') }}</button>
            </div>
        </div>
    </div>

    @error('periods') <div class="alert alert-danger py-2 fs-13">{{ $message }}</div> @enderror

    <div class="table-responsive">
        <table class="table table-sm align-middle mb-2">
            <thead>
                <tr>
                    <th scope="col" style="width: 6rem">{{ __('Period') }}</th>
                    <th scope="col">{{ __('Starts') }}</th>
                    <th scope="col">{{ __('Ends') }}</th>
                    <th scope="col" class="text-end"><span class="visually-hidden">{{ __('Remove') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($periods as $index => $period)
                    <tr wire:key="period-row-{{ $index }}">
                        <td class="fw-medium">{{ $index + 1 }}</td>
                        <td><x-form.input name="periods.{{ $index }}.start" type="time" wrapper="mb-0" aria-label="{{ __('Period :number starts', ['number' => $index + 1]) }}" /></td>
                        <td><x-form.input name="periods.{{ $index }}.end" type="time" wrapper="mb-0" aria-label="{{ __('Period :number ends', ['number' => $index + 1]) }}" /></td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-text-danger icon-btn-sm" wire:click="removePeriod({{ $index }})" aria-label="{{ __('Remove period :number', ['number' => $index + 1]) }}">
                                <i class="ri-delete-bin-line"></i>
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <button type="button" class="btn btn-sm btn-light" wire:click="addPeriod" @disabled(count($periods) >= 16)>
        <i class="ri-add-line me-1"></i>{{ __('Add a period') }}
    </button>

    @if ($this->lost > 0)
        <div class="alert alert-warning mt-4 mb-0 fs-13">
            <i class="ri-error-warning-line me-1"></i>{{ trans_choice('Removing periods clears :count timetable slot that uses them.|Removing periods clears :count timetable slots that use them.', $this->lost, ['count' => $this->lost]) }}
        </div>
    @endif
</x-modal.form>
