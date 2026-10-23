<?php

/*
 * Raise one invoice for a student: the fees that apply to them (discounts
 * worked out automatically) plus any one-off charges.
 *     $dispatch('open-modal', { component: 'forms.invoice', size: 'lg' })
 *     $dispatch('open-modal', { component: 'forms.invoice', params: { student: 12 }, size: 'lg' })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Livewire\Concerns\PicksStudent;
use App\Models\FeeStructure;
use App\Models\StudentProfile;
use App\Services\Attendance\SchoolCalendar;
use App\Services\Fees\FeeCalculator;
use App\Services\Fees\FeeSchedule;
use App\Services\Fees\Invoices;
use App\Support\Billing\Money;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;

use function Livewire\Volt\{computed, mount, protect, rules, state, updated, uses};

uses([InteractsWithUi::class, PicksStudent::class]);

state(['fixedStudent' => false])->locked();

state([
    'billing_period' => '', 'issue_date' => '', 'due_date' => '',
    'fees' => [],      // fee id (string) => bool
    'custom' => [],    // [['description' => '', 'amount' => '']]
    'notes' => '',
]);

mount(function (?int $student = null) {
    $this->authorize('invoices.create');

    $today = tenant()->today();
    $this->billing_period = $today->format('Y-m');
    $this->issue_date = $today->toDateString();

    if ($student) {
        $this->fixedStudent = true;
        $this->setPickedStudent(StudentProfile::query()->findOrFail($student));
    }

    $this->preselect();
});

$student = computed(fn () => $this->pickedStudent());

$period = computed(fn () => FeeSchedule::isPeriod($this->billing_period) ? $this->billing_period : null);

// Fees offered for the picked student: their class's fees for the period's year.
$options = computed(function () {
    if (! $this->student) {
        return collect();
    }

    $schedule = app(FeeSchedule::class);
    $year = $this->period ? $schedule->yearFor($this->period) : app(SchoolCalendar::class)->yearFor(tenant()->today());

    return $schedule->feesFor($year, $this->student->class_id)
        ->filter(fn (FeeStructure $fee) => $fee->class_id === null || $fee->class_id === $this->student->class_id)
        ->values();
});

// The fee lines as they would be billed, keyed by fee id.
$lines = computed(function () {
    if (! $this->student) {
        return [];
    }

    $invoices = app(Invoices::class);
    $calculator = app(FeeCalculator::class);
    $on = $this->period ? FeeSchedule::periodStart($this->period) : Carbon::parse($this->issue_date ?: tenant()->today());
    $lines = [];

    foreach ($this->options as $fee) {
        $lines[$fee->id] = [
            ...$calculator->line($this->student, $fee, $on, $this->period),
            'billed' => $invoices->alreadyBilled($this->student, $fee, $this->period),
            'fee' => $fee,
        ];
    }

    return $lines;
});

$total = computed(function () {
    $total = 0;

    foreach ($this->lines as $id => $line) {
        if (! $line['billed'] && ($this->fees[(string) $id] ?? false)) {
            $total += FeeCalculator::cents($line['net_amount']);
        }
    }

    foreach ($this->custom as $row) {
        $total += is_numeric($row['amount'] ?? null) ? FeeCalculator::cents($row['amount']) : 0;
    }

    return $total / 100;
});

// Tick the fees due in the period that haven't been billed, and suggest a due date.
$preselect = protect(function () {
    unset($this->student, $this->options, $this->lines);
    $this->fees = [];

    if (! $this->student) {
        return;
    }

    $schedule = app(FeeSchedule::class);
    $year = $this->period ? $schedule->yearFor($this->period) : null;
    $due = collect();

    foreach ($this->options as $fee) {
        $isDue = $this->period ? $schedule->isDue($fee, $this->period, $year) : false;
        $this->fees[(string) $fee->id] = $isDue && ! ($this->lines[$fee->id]['billed'] ?? false);

        if ($this->fees[(string) $fee->id]) {
            $due->push($fee);
        }
    }

    $this->due_date = $this->period
        ? $schedule->dueDateFor($this->period, $due)->max(Carbon::parse($this->issue_date ?: tenant()->today()))->toDateString()
        : Carbon::parse($this->issue_date ?: tenant()->today())->addDays(FeeSchedule::DEFAULT_DUE_DAY)->toDateString();
});

$studentPicked = protect(fn () => $this->preselect());

updated(['billing_period' => fn () => $this->preselect()]);

$addCustom = fn () => $this->custom[] = ['description' => '', 'amount' => ''];

$removeCustom = function (int $index) {
    unset($this->custom[$index]);
    $this->custom = array_values($this->custom);
};

rules(fn () => [
    'student_id' => ['required'],
    'billing_period' => ['nullable', 'date_format:Y-m'],
    'issue_date' => ['required', 'date'],
    'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
    'fees' => ['array'],
    'custom' => ['array', 'max:20'],
    'custom.*.description' => ['required', 'string', 'max:255'],
    'custom.*.amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
    'notes' => ['nullable', 'string', 'max:1000'],
])->attributes([
    'student_id' => __('student'),
    'billing_period' => __('month'),
    'custom.*.description' => __('description'),
    'custom.*.amount' => __('amount'),
]);

$save = function (Invoices $invoices) {
    $this->authorize('invoices.create');
    $data = $this->validate();
    $student = $this->student ?? abort(404);

    $chosen = $this->options->filter(fn (FeeStructure $fee) => $data['fees'][(string) $fee->id] ?? false);
    $on = $this->period ? FeeSchedule::periodStart($this->period) : Carbon::parse($data['issue_date']);

    $lines = [
        ...$invoices->linesFor($student, $chosen, $this->period, $on),
        ...array_map(fn (array $row) => FeeCalculator::customLine($row['description'], $row['amount']), $data['custom']),
    ];

    try {
        $invoice = $invoices->create($student, $lines, Carbon::parse($data['issue_date']), Carbon::parse($data['due_date']), $this->period, auth()->user(), $data['notes']);
    } catch (ActionFailed $e) {
        $this->addError('fees', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->flashToast(__('Invoice :number raised.', ['number' => $invoice->invoice_number]));
    $this->redirectRoute('accounting.invoices.show', ['invoice' => $invoice->id], navigate: true);
};

?>

<x-modal.form :title="__('New invoice')" :submit-label="__('Raise invoice')">
    <x-form.student-picker :results="$this->studentResults" :selected="$studentLabel" :picked="$student_id" :search="$studentSearch" :locked="$fixedStudent" />

    <div class="row">
        <div class="col-sm-4"><x-form.input name="billing_period" type="month" :label="__('Month')" live :hint="__('Blank for a one-off bill.')" /></div>
        <div class="col-sm-4"><x-form.input name="issue_date" type="date" :label="__('Issue date')" required /></div>
        <div class="col-sm-4"><x-form.input name="due_date" type="date" :label="__('Due date')" required /></div>
    </div>

    @if ($this->student)
        <h6 class="mb-2">{{ __('Fees') }}</h6>
        @error('fees') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

        @if ($this->options->isEmpty())
            <p class="text-muted fs-13">{{ __('No fees are set up for :class.', ['class' => $this->student->schoolClass?->name ?? __('this student')]) }}</p>
        @else
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th></th><th>{{ __('Fee') }}</th><th class="text-end">{{ __('Amount') }}</th><th class="text-end">{{ __('Reduction') }}</th><th class="text-end">{{ __('To pay') }}</th></tr></thead>
                    <tbody>
                        @foreach ($this->lines as $id => $line)
                            <tr wire:key="fee-{{ $id }}" @class(['text-muted' => $line['billed']])>
                                <td style="width: 2rem">
                                    <input type="checkbox" class="form-check-input" id="fee-{{ $id }}" wire:model.live="fees.{{ $id }}" @disabled($line['billed'])>
                                </td>
                                <td>
                                    <label for="fee-{{ $id }}" class="mb-0">{{ $line['description'] }}</label>
                                    <small class="d-block text-muted">
                                        {{ $line['fee']->frequency->label() }}
                                        @if ($line['billed']) · <span class="text-warning">{{ __('already billed') }}</span> @endif
                                        @foreach ($line['discount_breakdown'] ?? [] as $reduction) · {{ $reduction['label'] }} @endforeach
                                    </small>
                                </td>
                                <td class="text-end">{{ Money::school($line['amount']) }}</td>
                                <td class="text-end">{{ $line['discount_amount'] > 0 ? '-'.Money::school($line['discount_amount']) : '—' }}</td>
                                <td class="text-end fw-medium">{{ Money::school($line['net_amount']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <h6 class="mb-2">{{ __('Other charges') }}</h6>
        @foreach ($custom as $index => $row)
            <div class="row g-2 mb-2" wire:key="custom-{{ $index }}">
                <div class="col">
                    <input type="text" wire:model="custom.{{ $index }}.description" maxlength="255" placeholder="{{ __('e.g. Lost library book') }}"
                           @class(['form-control', 'is-invalid' => $errors->has("custom.$index.description")]) aria-label="{{ __('Description') }}">
                </div>
                <div class="col-4 col-sm-3">
                    <input type="number" step="0.01" min="0.01" wire:model.live.blur="custom.{{ $index }}.amount" placeholder="{{ __('Amount') }}"
                           @class(['form-control', 'is-invalid' => $errors->has("custom.$index.amount")]) aria-label="{{ __('Amount') }}">
                </div>
                <div class="col-auto">
                    <button type="button" class="btn btn-subtle-danger icon-btn" wire:click="removeCustom({{ $index }})" aria-label="{{ __('Remove') }}"><i class="ri-delete-bin-line"></i></button>
                </div>
            </div>
        @endforeach
        <button type="button" class="btn btn-sm btn-light mb-4" wire:click="addCustom"><i class="ri-add-line me-1"></i>{{ __('Add a charge') }}</button>

        <x-form.textarea name="notes" :label="__('Notes')" rows="2" maxlength="1000" :hint="__('Printed on the invoice.')" />

        <div class="d-flex justify-content-between align-items-center border-top pt-3">
            <span class="text-muted">{{ __('Invoice total') }}</span>
            <span class="fs-5 fw-semibold">{{ Money::school($this->total) }}</span>
        </div>
    @else
        <p class="text-muted fs-13 mb-0">{{ __('Find the student to see the fees that apply to them.') }}</p>
    @endif
</x-modal.form>
