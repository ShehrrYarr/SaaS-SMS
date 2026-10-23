<?php

/*
 * Change a school's plan or trial end date (Super Admin). Paid periods come
 * from recorded payments, so they aren't edited here.
 *     $dispatch('open-modal', { component: 'forms.central.school-subscription', params: { school: 3 } })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Plan;
use App\Models\School;
use App\Services\Billing\PlanLimits;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['school' => null, 'name' => ''])->locked();

state(['plan_id' => '', 'trial_ends_at' => '']);

mount(function (int $school) {
    abort_unless(tenancy()->isCentral(), 404);
    $this->authorize('manage-subscriptions');

    $record = School::query()->findOrFail($school);

    $this->school = $record->id;
    $this->name = $record->name;
    $this->plan_id = (string) ($record->plan_id ?? '');
    $this->trial_ends_at = $record->trial_ends_at?->toDateString() ?? '';
});

// Retired plans stay selectable for the school that is already on one.
$plans = computed(fn () => Plan::query()
    ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', School::query()->whereKey($this->school)->value('plan_id')))
    ->ordered()
    ->get());

// Limits of the chosen plan that the school is already over.
$overLimits = computed(function () {
    $plan = $this->plans->firstWhere('id', (int) $this->plan_id);

    if (! $plan) {
        return [];
    }

    $school = School::query()->findOrFail($this->school);
    $limits = app(PlanLimits::class);
    $over = [];

    foreach (PlanLimits::RESOURCES as $resource) {
        $limit = $plan->limit($resource);
        $used = $limits->used($school, $resource);

        if ($limit !== null && $used > $limit) {
            $over[$resource] = compact('limit', 'used');
        }
    }

    return $over;
});

rules(fn () => [
    'plan_id' => ['required', Rule::exists('plans', 'id')->whereNull('deleted_at')],
    'trial_ends_at' => ['nullable', 'date'],
])->attributes(['plan_id' => __('plan'), 'trial_ends_at' => __('trial end date')]);

$save = function () {
    $this->authorize('manage-subscriptions');
    $data = $this->validate();

    $school = School::query()->findOrFail($this->school);
    $school->update([
        'plan_id' => (int) $data['plan_id'],
        'trial_ends_at' => $data['trial_ends_at'] ?: null,
    ]);

    if ($school->wasChanged()) {
        activity('billing')->performedOn($school)->causedBy(auth()->user())
            ->withProperties($school->getChanges())
            ->log('Plan or trial changed');
    }

    $this->closeModal();
    $this->refreshTables();
    $this->toast(__('Subscription updated.'));
};

?>

<x-modal.form :title="__('Plan & trial')" :subtitle="$name">
    <x-form.select name="plan_id" :label="__('Plan')" required live
                   :options="$this->plans->mapWithKeys(fn ($plan) => [$plan->id => $plan->name.' — '.$plan->formattedPrice().' '.$plan->billing_cycle->per().($plan->is_active ? '' : ' ('.__('retired').')')])->all()" />

    @foreach ($this->overLimits as $resource => $over)
        <div class="alert alert-warning fs-13 py-2" role="alert">
            {{ $resource === 'students'
                ? __('The school has :used active students; this plan allows :limit. Nothing is removed, but no new students can be added.', $over)
                : __('The school has :used staff; this plan allows :limit. Nothing is removed, but no new staff can be added.', $over) }}
        </div>
    @endforeach

    <x-form.input name="trial_ends_at" type="date" :label="__('Trial ends on')" wrapper="mb-0"
                  :hint="__('Leave empty for no trial. Extending the trial is how to give a school more time without a payment.')" />
</x-modal.form>
