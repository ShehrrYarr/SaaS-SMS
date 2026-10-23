<?php

/*
 * Record a subscription payment (Super Admin). Extends the school's paid-until
 * date to the end of the period it covers.
 *     $dispatch('open-modal', { component: 'forms.central.subscription-payment', size: 'lg' })
 *     $dispatch('open-modal', { component: 'forms.central.subscription-payment', params: { school: 3 }, size: 'lg' })
 */

use App\Enums\PaymentMethod;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Plan;
use App\Models\School;
use App\Services\Billing\SubscriptionManager;
use App\Support\Billing\Money;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, protect, rules, state, updated, uses};

uses([InteractsWithUi::class]);

state(['fixedSchool' => false])->locked();

state([
    'school_id' => '', 'plan_id' => '', 'amount' => '', 'period_start' => '', 'period_end' => '',
    'paid_on' => '', 'payment_method' => PaymentMethod::BankTransfer->value, 'reference' => '', 'notes' => '',
    'reactivate' => true,
]);

mount(function (?int $school = null) {
    abort_unless(tenancy()->isCentral(), 404);
    $this->authorize('manage-subscriptions');

    $this->paid_on = today()->toDateString();

    if ($school) {
        $this->fixedSchool = true;
        $this->school_id = (string) School::query()->findOrFail($school)->id;
        $this->prefill();
    }
});

$schools = computed(fn () => School::query()->orderBy('name')->pluck('name', 'id')->all());

$plans = computed(fn () => Plan::query()->ordered()->get());

$selectedSchool = computed(fn () => $this->school_id ? School::query()->with('plan')->find($this->school_id) : null);

// Suggest the next period and the plan price for the chosen school.
$prefill = protect(function () {
    $school = School::query()->with('plan')->find($this->school_id);

    if (! $school) {
        return;
    }

    $this->plan_id = (string) ($school->plan_id ?? '');
    $this->amount = $school->plan ? (string) $school->plan->price : '';

    [$start, $end] = app(SubscriptionManager::class)->suggestPeriod($school);
    $this->period_start = $start->toDateString();
    $this->period_end = $end->toDateString();
});

$fillPeriodEnd = protect(function () {
    $start = rescue(fn () => Carbon::parse($this->period_start), null, false);
    $plan = $this->plans->firstWhere('id', (int) $this->plan_id);

    if ($start && $plan) {
        $this->period_end = app(SubscriptionManager::class)->periodEnd($start, $plan->billing_cycle)->toDateString();
    }
});

updated([
    'school_id' => fn () => $this->prefill(),
    'plan_id' => function () {
        $plan = $this->plans->firstWhere('id', (int) $this->plan_id);
        $this->amount = $plan ? (string) $plan->price : $this->amount;
        $this->fillPeriodEnd();
    },
    'period_start' => fn () => $this->fillPeriodEnd(),
]);

rules(fn () => [
    'school_id' => ['required', Rule::exists('schools', 'id')->whereNull('deleted_at')],
    'plan_id' => ['nullable', Rule::exists('plans', 'id')],
    'amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
    'period_start' => ['required', 'date'],
    'period_end' => ['required', 'date', 'after_or_equal:period_start'],
    'paid_on' => ['required', 'date', 'before_or_equal:today'],
    'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
    'reference' => ['nullable', 'string', 'max:100'],
    'notes' => ['nullable', 'string', 'max:1000'],
    'reactivate' => ['boolean'],
])->attributes([
    'school_id' => __('school'),
    'plan_id' => __('plan'),
    'period_start' => __('period start'),
    'period_end' => __('period end'),
    'paid_on' => __('payment date'),
    'payment_method' => __('payment method'),
]);

$save = function (SubscriptionManager $subscriptions) {
    $this->authorize('manage-subscriptions');
    $data = $this->validate();

    $school = School::query()->findOrFail($data['school_id']);

    $subscriptions->recordPayment($school, [
        'plan_id' => $data['plan_id'] ?: null,
        'amount' => $data['amount'],
        'period_start' => $data['period_start'],
        'period_end' => $data['period_end'],
        'paid_on' => $data['paid_on'],
        'payment_method' => $data['payment_method'],
        'reference' => $data['reference'] ?: null,
        'notes' => $data['notes'] ?: null,
    ], auth()->user(), reactivate: (bool) $data['reactivate']);

    $school->refresh();

    $this->closeModal();
    $this->refreshTables();
    $this->toast(__('Payment recorded. :name is paid until :date.', [
        'name' => $school->name,
        'date' => $school->subscription_ends_at->format('d M Y'),
    ]));
};

?>

<x-modal.form :title="__('Record payment')" :submit-label="__('Record payment')"
              :subtitle="__('Payments are collected outside the system; this keeps the record and extends the subscription.')">
    @if (! $fixedSchool)
        <x-form.select name="school_id" :label="__('School')" :options="$this->schools" required live />
    @endif

    @if ($school = $this->selectedSchool)
        <div class="d-flex flex-wrap align-items-center gap-2 mb-4 fs-13">
            <span class="fw-medium">{{ $school->name }}</span>
            @php($state = $school->subscriptionState())
            <span class="badge bg-{{ $state->color() }}-subtle text-{{ $state->color() }}">{{ $state->label() }}</span>
            <span class="text-muted">
                {{ $school->subscription_ends_at ? __('Paid until :date', ['date' => $school->subscription_ends_at->format('d M Y')]) : __('No payments yet') }}
            </span>
        </div>

        <div class="row">
            <div class="col-sm-6">
                <x-form.select name="plan_id" :label="__('Plan')" live :placeholder="__('No plan')"
                               :options="$this->plans->mapWithKeys(fn ($plan) => [$plan->id => $plan->name])->all()" />
            </div>
            <div class="col-sm-6">
                <x-form.input name="amount" type="number" step="0.01" min="0" required :label="__('Amount (:currency)', ['currency' => Money::currency()])" />
            </div>
            <div class="col-sm-6"><x-form.input name="period_start" type="date" :label="__('Covers from')" required live /></div>
            <div class="col-sm-6"><x-form.input name="period_end" type="date" :label="__('Covers until')" required /></div>
            <div class="col-sm-6"><x-form.input name="paid_on" type="date" :label="__('Paid on')" required :max="today()->toDateString()" /></div>
            <div class="col-sm-6"><x-form.select name="payment_method" :label="__('Method')" :options="PaymentMethod::options()" :placeholder="false" required /></div>
            <div class="col-sm-6"><x-form.input name="reference" :label="__('Reference')" maxlength="100" :placeholder="__('Receipt or transfer no.')" /></div>
            <div class="col-sm-6"><x-form.textarea name="notes" :label="__('Notes')" rows="1" maxlength="1000" /></div>
        </div>

        @if ($school->isSuspended())
            <x-form.switch name="reactivate" :label="__('Reactivate the school')" wrapper="mb-0"
                           :hint="__('It is suspended: :reason', ['reason' => $school->suspension_reason ?: __('no reason given')])" />
        @endif
    @endif
</x-modal.form>
