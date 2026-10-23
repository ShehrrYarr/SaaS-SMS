<?php

/*
 * Start a bulk invoice run for a month.
 *     $dispatch('open-modal', { component: 'forms.invoice-batch', size: 'lg' })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\FeeStructure;
use App\Models\SchoolClass;
use App\Services\Fees\FeeSchedule;
use App\Services\Fees\InvoiceBatches;
use App\Support\Billing\Money;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;

use function Livewire\Volt\{computed, mount, protect, rules, state, updated, uses};

uses([InteractsWithUi::class]);

state([
    'billing_period' => '', 'title' => '', 'issue_date' => '', 'due_date' => '',
    'class_ids' => [],  // strings; none = every class
    'fee_ids' => [],    // strings
]);

mount(function () {
    $this->authorize('invoices.generate');

    $today = tenant()->today();
    $this->billing_period = $today->format('Y-m');
    $this->issue_date = $today->toDateString();
    $this->preselect();
});

$period = computed(fn () => FeeSchedule::isPeriod($this->billing_period) ? $this->billing_period : null);

$classes = computed(fn () => SchoolClass::query()->ordered()->pluck('name', 'id')->all());

$fees = computed(fn () => $this->period
    ? app(FeeSchedule::class)->feesFor(app(FeeSchedule::class)->yearFor($this->period))
    : collect());

$studentCount = computed(fn () => app(InvoiceBatches::class)->students($this->class_ids)->count());

// Tick the fees due in the month, name the run, and suggest a due date.
$preselect = protect(function () {
    unset($this->fees);

    if (! $this->period) {
        return;
    }

    $schedule = app(FeeSchedule::class);
    $year = $schedule->yearFor($this->period);
    $due = $this->fees->filter(fn (FeeStructure $fee) => $schedule->isDue($fee, $this->period, $year));

    $this->fee_ids = $due->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
    $this->title = __(':period fees', ['period' => FeeSchedule::periodLabel($this->period)]);
    $this->due_date = $schedule->dueDateFor($this->period, $due)->max(Carbon::parse($this->issue_date ?: tenant()->today()))->toDateString();
});

updated(['billing_period' => fn () => $this->preselect()]);

rules(fn () => [
    'billing_period' => ['required', 'date_format:Y-m'],
    'title' => ['required', 'string', 'max:150'],
    'issue_date' => ['required', 'date'],
    'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
    'class_ids' => ['array'],
    'class_ids.*' => ['in:'.implode(',', array_keys($this->classes))],
    'fee_ids' => ['required', 'array', 'min:1'],
    'fee_ids.*' => ['in:'.$this->fees->pluck('id')->implode(',')],
])->attributes([
    'billing_period' => __('month'),
    'fee_ids' => __('fees'),
    'class_ids' => __('classes'),
])->messages([
    'fee_ids.required' => __('Choose at least one fee to bill.'),
    'fee_ids.min' => __('Choose at least one fee to bill.'),
]);

$save = function (InvoiceBatches $batches) {
    $this->authorize('invoices.generate');
    $data = $this->validate();

    try {
        $batch = $batches->start([
            'title' => $data['title'],
            'billing_period' => $data['billing_period'],
            'class_ids' => array_map('intval', $data['class_ids']),
            'fee_structure_ids' => array_map('intval', $data['fee_ids']),
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'],
        ], auth()->user());
    } catch (ActionFailed $e) {
        $this->addError('billing_period', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->dispatch('batch-started');
    $this->toast(trans_choice('Generating invoices for :count student.|Generating invoices for :count students.', $batch->total_students, ['count' => $batch->total_students]));
};

?>

<x-modal.form :title="__('Generate invoices')" :subtitle="__('Runs in the background; you can leave this page.')" :submit-label="__('Generate')">
    <div class="row">
        <div class="col-sm-4"><x-form.input name="billing_period" type="month" :label="__('Month')" required live /></div>
        <div class="col-sm-8"><x-form.input name="title" :label="__('Name of this run')" required maxlength="150" /></div>
        <div class="col-sm-6"><x-form.input name="issue_date" type="date" :label="__('Issue date')" required /></div>
        <div class="col-sm-6"><x-form.input name="due_date" type="date" :label="__('Due date')" required /></div>
    </div>

    <fieldset class="mb-4">
        <legend class="form-label fs-14">{{ __('Fees to bill') }}<span class="text-danger ms-1">*</span></legend>
        @forelse ($this->fees as $fee)
            <div class="form-check" wire:key="batch-fee-{{ $fee->id }}">
                <input class="form-check-input" type="checkbox" id="batch-fee-{{ $fee->id }}" value="{{ $fee->id }}" wire:model="fee_ids">
                <label class="form-check-label" for="batch-fee-{{ $fee->id }}">
                    {{ $fee->title }} <span class="text-muted">· {{ Money::school($fee->amount) }} · {{ $fee->frequency->label() }} · {{ $fee->schoolClass?->name ?? __('All classes') }}</span>
                </label>
            </div>
        @empty
            <p class="text-muted fs-13 mb-0">{{ __('No active fees for this month\'s academic year.') }}</p>
        @endforelse
        @error('fee_ids') <div class="text-danger fs-13 mt-1">{{ $message }}</div> @enderror
        <div class="form-text">{{ __('Fees due this month are ticked. Students already billed for a fee this month are skipped for it.') }}</div>
    </fieldset>

    <fieldset class="mb-3">
        <legend class="form-label fs-14">{{ __('Classes') }}</legend>
        <div class="d-flex flex-wrap gap-2">
            @foreach ($this->classes as $id => $name)
                <input type="checkbox" class="btn-check" id="batch-class-{{ $id }}" value="{{ $id }}" wire:model.live="class_ids" autocomplete="off">
                <label class="btn btn-sm btn-outline-primary" for="batch-class-{{ $id }}" wire:key="batch-class-{{ $id }}">{{ $name }}</label>
            @endforeach
        </div>
        <div class="form-text">{{ __('Leave all unselected to bill every class.') }}</div>
    </fieldset>

    <p class="mb-0"><i class="ri-group-line me-1"></i>{{ trans_choice(':count active student will be billed.|:count active students will be billed.', $this->studentCount, ['count' => $this->studentCount]) }}</p>
</x-modal.form>
