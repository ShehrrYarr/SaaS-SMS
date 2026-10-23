<?php

/*
 * Create / edit a SaaS plan (Super Admin).
 *     $dispatch('open-modal', { component: 'forms.central.plan', size: 'lg' })
 *     $dispatch('open-modal', { component: 'forms.central.plan', params: { plan: 2 }, size: 'lg' })
 */

use App\Enums\BillingCycle;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Plan;
use App\Support\Billing\Money;
use App\Support\Billing\PlanFeatures;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{mount, protect, rules, state, uses};

uses([InteractsWithUi::class]);

state(['plan' => null])->locked();

state([
    'name' => '', 'description' => '', 'price' => '', 'billing_cycle' => BillingCycle::Monthly->value,
    'max_students' => '', 'max_staff' => '', 'trial_days' => '14',
    'all_features' => true, 'features' => [],
    'is_active' => true, 'sort_order' => '0',
]);

mount(function (?int $plan = null) {
    abort_unless(tenancy()->isCentral(), 404);
    $this->authorize('manage-subscriptions');

    if (! $plan) {
        $this->sort_order = (string) ((int) Plan::query()->max('sort_order') + 1);

        return;
    }

    $record = Plan::query()->findOrFail($plan);

    $this->plan = $record->id;
    $this->fill([
        'name' => $record->name,
        'description' => (string) $record->description,
        'price' => (string) $record->price,
        'billing_cycle' => $record->billing_cycle->value,
        'max_students' => (string) ($record->max_students ?? ''),
        'max_staff' => (string) ($record->max_staff ?? ''),
        'trial_days' => (string) $record->trial_days,
        'all_features' => $record->includesAllFeatures(),
        'features' => $record->features ?? [],
        'is_active' => $record->is_active,
        'sort_order' => (string) $record->sort_order,
    ]);
});

rules(fn () => [
    'name' => ['required', 'string', 'max:100', Rule::unique('plans', 'name')->ignore($this->plan)->whereNull('deleted_at')],
    'description' => ['nullable', 'string', 'max:500'],
    'price' => ['required', 'numeric', 'min:0', 'max:99999999'],
    'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
    'max_students' => ['nullable', 'integer', 'min:1', 'max:1000000'],
    'max_staff' => ['nullable', 'integer', 'min:1', 'max:100000'],
    'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
    'all_features' => ['boolean'],
    'features' => ['array'],
    'features.*' => [Rule::in(PlanFeatures::keys())],
    'is_active' => ['boolean'],
    'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
])->attributes([
    'billing_cycle' => __('billing cycle'),
    'max_students' => __('student limit'),
    'max_staff' => __('staff limit'),
    'trial_days' => __('trial length'),
    'sort_order' => __('display order'),
]);

$save = function () {
    $this->authorize('manage-subscriptions');
    $data = $this->validate();

    $attributes = [
        'name' => $data['name'],
        'description' => $data['description'] ?: null,
        'price' => $data['price'],
        'billing_cycle' => $data['billing_cycle'],
        'max_students' => $data['max_students'] === '' || $data['max_students'] === null ? null : (int) $data['max_students'],
        'max_staff' => $data['max_staff'] === '' || $data['max_staff'] === null ? null : (int) $data['max_staff'],
        'trial_days' => (int) $data['trial_days'],
        // Keep config order, so the list reads the same everywhere.
        'features' => $data['all_features'] ? null : array_values(array_intersect(PlanFeatures::keys(), $data['features'])),
        'is_active' => (bool) $data['is_active'],
        'sort_order' => (int) $data['sort_order'],
    ];

    if ($this->plan) {
        Plan::query()->findOrFail($this->plan)->update($attributes);
    } else {
        Plan::query()->create($attributes + ['slug' => $this->uniqueSlug($data['name']), 'currency' => Money::currency()]);
    }

    $this->closeModal();
    $this->refreshTables();
    $this->toast($this->plan ? __('Plan updated.') : __('Plan created.'));
};

$uniqueSlug = protect(function (string $name) {
    $base = Str::slug($name) ?: 'plan';
    $slug = $base;

    for ($i = 2; Plan::query()->withTrashed()->where('slug', $slug)->exists(); $i++) {
        $slug = "{$base}-{$i}";
    }

    return $slug;
});

?>

<x-modal.form :title="$plan ? __('Edit plan') : __('New plan')"
              :subtitle="__('Changes apply straight away to every school on the plan.')">
    <div class="row">
        <div class="col-sm-8"><x-form.input name="name" :label="__('Name')" required maxlength="100" /></div>
        <div class="col-sm-4"><x-form.input name="sort_order" type="number" min="0" :label="__('Display order')" required /></div>
    </div>

    <x-form.textarea name="description" :label="__('Description')" rows="2" maxlength="500" />

    <div class="row">
        <div class="col-sm-4"><x-form.input name="price" type="number" step="0.01" min="0" required :label="__('Price (:currency)', ['currency' => Money::currency()])" /></div>
        <div class="col-sm-4"><x-form.select name="billing_cycle" :label="__('Billed')" :options="BillingCycle::options()" :placeholder="null" required /></div>
        <div class="col-sm-4"><x-form.input name="trial_days" type="number" min="0" max="365" required :label="__('Trial (days)')" /></div>
        <div class="col-sm-6"><x-form.input name="max_students" type="number" min="1" :label="__('Max active students')" :placeholder="__('Unlimited')" /></div>
        <div class="col-sm-6"><x-form.input name="max_staff" type="number" min="1" :label="__('Max staff')" :placeholder="__('Unlimited')" /></div>
    </div>

    <fieldset class="mb-4">
        <legend class="form-label fs-14 mb-2">{{ __('Features') }}</legend>
        <p class="text-muted fs-13 mb-2">{{ __('Academics, students, parents, staff records, attendance, notices and reports come with every plan.') }}</p>

        <x-form.switch name="all_features" :label="__('Include every feature, including ones added later')" live wrapper="mb-3" />

        @unless ($all_features)
            <div class="row g-2">
                @foreach (PlanFeatures::options() as $key => $label)
                    <div class="col-sm-6">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="feature-{{ $key }}" value="{{ $key }}" wire:model="features">
                            <label class="form-check-label" for="feature-{{ $key }}">{{ $label }}</label>
                        </div>
                    </div>
                @endforeach
            </div>
            @error('features.*') <div class="text-danger fs-13 mt-1">{{ $message }}</div> @enderror
        @endunless
    </fieldset>

    <x-form.switch name="is_active" :label="__('Offered to new schools')" wrapper="mb-0"
                   :hint="__('Turn off to retire the plan. Schools already on it keep it.')" />
</x-modal.form>
