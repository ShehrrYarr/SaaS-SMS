<?php

/*
 * A draft payroll record: the automatic deductions (read-only) and the
 * allowances and deductions HR adds by hand.
 *     $dispatch('open-modal', { component: 'forms.payroll-record', params: { record: 5 }, size: 'lg' })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\PayrollRecord;
use App\Services\Fees\FeeCalculator;
use App\Services\Payroll\Payroll;
use App\Support\Billing\Money;
use App\Tables\ActionFailed;

use function Livewire\Volt\{computed, mount, state, uses};

uses([InteractsWithUi::class]);

state(['record' => null])->locked();

state(['allowances' => [], 'deductions' => []]);

mount(function (int $record) {
    $this->authorize('payroll.generate');
    $payroll = PayrollRecord::query()->findOrFail($record);

    abort_unless($payroll->isDraft(), 403);

    $this->record = $payroll->id;
    $this->allowances = array_map(fn (array $line) => ['label' => $line['label'], 'amount' => Money::input($line['amount'])], Payroll::manual($payroll->breakdown['allowances'] ?? []));
    $this->deductions = array_map(fn (array $line) => ['label' => $line['label'], 'amount' => Money::input($line['amount'])], Payroll::manual($payroll->breakdown['deductions'] ?? []));
});

$details = computed(fn () => PayrollRecord::query()->with('staff.user')->findOrFail($this->record));

$automatic = computed(fn () => array_values(array_filter($this->details->breakdown['deductions'] ?? [], fn (array $line) => $line['auto'] ?? false)));

// Net pay with the lines as currently typed.
$net = computed(function () {
    $sum = fn (array $lines) => array_sum(array_map(fn (array $line) => is_numeric($line['amount'] ?? null) ? FeeCalculator::cents($line['amount']) : 0, $lines));
    $auto = array_sum(array_map(fn (array $line) => FeeCalculator::cents($line['amount']), $this->automatic));
    $gross = FeeCalculator::cents($this->details->basic_salary) + $sum($this->allowances);

    return max($gross - $auto - $sum($this->deductions), 0) / 100;
});

$add = function (string $section) {
    abort_unless(in_array($section, ['allowances', 'deductions'], true), 404);
    $this->{$section}[] = ['label' => '', 'amount' => ''];
};

$remove = function (string $section, int $index) {
    abort_unless(in_array($section, ['allowances', 'deductions'], true), 404);
    $lines = $this->{$section};
    array_splice($lines, $index, 1);
    $this->{$section} = $lines;
};

$save = function (Payroll $payroll) {
    $this->authorize('payroll.generate');
    $this->validate([
        'allowances' => ['array', 'max:20'],
        'allowances.*.label' => ['required', 'string', 'max:100'],
        'allowances.*.amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
        'deductions' => ['array', 'max:20'],
        'deductions.*.label' => ['required', 'string', 'max:100'],
        'deductions.*.amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
    ], attributes: [
        'allowances.*.label' => __('description'), 'allowances.*.amount' => __('amount'),
        'deductions.*.label' => __('description'), 'deductions.*.amount' => __('amount'),
    ]);

    try {
        $payroll->updateLines(PayrollRecord::query()->findOrFail($this->record), $this->allowances, $this->deductions);
    } catch (ActionFailed $e) {
        $this->toast($e->getMessage(), 'error');

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->toast(__('Payroll updated.'));
};

?>

@php
    $record = $this->details;
    $attendance = $record->breakdown['attendance'] ?? [];
    $days = fn ($value) => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
@endphp

<x-modal.form :title="$record->staff?->user?->name" :subtitle="__('Payroll for :month', ['month' => $record->monthLabel()])">
    <div class="d-flex flex-wrap gap-3 mb-4 fs-13 text-muted">
        <span>{{ __('Working days: :days', ['days' => $attendance['working_days'] ?? $record->working_days]) }}</span>
        <span>{{ __('Worked: :days', ['days' => $days($attendance['present'] ?? 0)]) }}</span>
        <span>{{ __('Paid leave: :days', ['days' => $days($attendance['paid_leave'] ?? 0)]) }}</span>
        <span>{{ __('Unpaid leave: :days', ['days' => $days($attendance['unpaid_leave'] ?? 0)]) }}</span>
        <span>{{ __('Absent: :days', ['days' => $days($attendance['absent'] ?? 0)]) }}</span>
    </div>

    <div class="d-flex justify-content-between mb-3"><span>{{ __('Basic salary') }}</span><span class="fw-medium">{{ Money::school($record->basic_salary) }}</span></div>

    @foreach (['allowances' => __('Allowances'), 'deductions' => __('Deductions')] as $section => $heading)
        <h6 class="mb-2">{{ $heading }}</h6>
        @if ($section === 'deductions')
            @foreach ($this->automatic as $line)
                <div class="d-flex justify-content-between fs-13 mb-2 text-muted">
                    <span><i class="ri-robot-2-line me-1"></i>{{ $line['label'] }}</span><span>-{{ Money::school($line['amount']) }}</span>
                </div>
            @endforeach
        @endif
        @foreach ($this->{$section} as $index => $line)
            <div class="row g-2 mb-2" wire:key="{{ $section }}-{{ $index }}">
                <div class="col">
                    <input type="text" wire:model="{{ $section }}.{{ $index }}.label" maxlength="100" aria-label="{{ __('Description') }}"
                           placeholder="{{ $section === 'allowances' ? __('e.g. Transport allowance') : __('e.g. Salary advance') }}"
                           @class(['form-control', 'is-invalid' => $errors->has("$section.$index.label")])>
                </div>
                <div class="col-4 col-sm-3">
                    <input type="number" step="0.01" min="0.01" wire:model.live.blur="{{ $section }}.{{ $index }}.amount" aria-label="{{ __('Amount') }}" placeholder="{{ __('Amount') }}"
                           @class(['form-control', 'is-invalid' => $errors->has("$section.$index.amount")])>
                </div>
                <div class="col-auto">
                    <button type="button" class="btn btn-subtle-danger icon-btn" wire:click="remove('{{ $section }}', {{ $index }})" aria-label="{{ __('Remove') }}"><i class="ri-delete-bin-line"></i></button>
                </div>
            </div>
        @endforeach
        <button type="button" class="btn btn-sm btn-light mb-4" wire:click="add('{{ $section }}')"><i class="ri-add-line me-1"></i>{{ $section === 'allowances' ? __('Add an allowance') : __('Add a deduction') }}</button>
    @endforeach

    <div class="d-flex justify-content-between align-items-center border-top pt-3">
        <span class="text-muted">{{ __('Net pay') }}</span>
        <span class="fs-5 fw-semibold">{{ Money::school($this->net) }}</span>
    </div>
</x-modal.form>
