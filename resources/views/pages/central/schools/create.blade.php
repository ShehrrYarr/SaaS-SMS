<?php

/*
 * School provisioning wizard: details → plan & billing → administrator →
 * review. Creating the school runs App\Services\Schools\ProvisionSchool and
 * shows the administrator's sign-in details once.
 */

use App\Enums\PaymentMethod;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Plan;
use App\Rules\SchoolSlug;
use App\Services\Billing\SubscriptionManager;
use App\Services\Schools\ProvisionSchool;
use App\Support\Billing\Money;
use App\Support\Locale\LocaleOptions;
use App\Support\Passwords;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Livewire\Volt\{computed, layout, mount, protect, state, title, updated, uses};

layout('layouts.super-admin');
title(__('New school'));

uses([InteractsWithUi::class]);

state(['step' => 1, 'created' => null]);

state([
    // 1. School
    'name' => '', 'slug' => '', 'slugTouched' => false,
    'email' => '', 'phone' => '', 'address' => '', 'city' => '', 'country' => '',

    // 2. Plan, billing and regional settings
    'plan_id' => null, 'billing' => 'trial', 'trial_ends_at' => '',
    'amount' => '', 'period_start' => '', 'period_end' => '', 'paid_on' => '',
    'payment_method' => PaymentMethod::BankTransfer->value, 'reference' => '',
    'currency_code' => 'USD', 'currency_symbol' => '$', 'timezone' => '', 'date_format' => 'd M Y',

    // 3. First administrator
    'admin_name' => '', 'admin_username' => 'admin', 'admin_email' => '', 'admin_phone' => '', 'admin_password' => '',
]);

$steps = computed(fn () => [
    1 => __('School'),
    2 => __('Plan & billing'),
    3 => __('Administrator'),
    4 => __('Review'),
]);

$plans = computed(fn () => Plan::query()->active()->ordered()->get());

$plan = computed(fn () => $this->plan_id ? Plan::query()->find($this->plan_id) : null);

mount(function () {
    $this->authorize('manage-schools');

    $this->timezone = config('app.timezone');
    $this->admin_password = Passwords::generate();
    $this->choosePlan($this->plans->first()?->id);
});

$choosePlan = protect(function (?int $planId) {
    $this->plan_id = $planId;
    $plan = $planId ? Plan::query()->find($planId) : null;

    $this->trial_ends_at = today()->addDays($plan?->trial_days ?? 0)->toDateString();
    $this->amount = $plan ? (string) $plan->price : '';
    $this->period_start = today()->toDateString();
    $this->paid_on = today()->toDateString();
    $this->fillPeriodEnd();

    if ($plan && $plan->trial_days === 0 && $this->billing === 'trial') {
        $this->billing = 'paid';
    }
});

$fillPeriodEnd = protect(function () {
    $start = rescue(fn () => Carbon::parse($this->period_start), null, false);
    $plan = $this->plan_id ? Plan::query()->find($this->plan_id) : null;

    if ($start && $plan) {
        $this->period_end = app(SubscriptionManager::class)->periodEnd($start, $plan->billing_cycle)->toDateString();
    }
});

updated([
    'name' => function () {
        if (! $this->slugTouched) {
            $this->slug = Str::limit(Str::slug($this->name), 60, '');
        }
    },
    'slug' => function () {
        $this->slugTouched = true;
        $this->slug = Str::lower(trim($this->slug));
        $this->validateOnly('slug', $this->rulesFor(1), [], $this->attributesFor());
    },
    'plan_id' => fn () => $this->choosePlan($this->plan_id ? (int) $this->plan_id : null),
    'period_start' => fn () => $this->fillPeriodEnd(),
    'currency_code' => fn () => $this->currency_symbol = LocaleOptions::symbolFor($this->currency_code),
]);

$rulesFor = protect(fn (int $step) => match ($step) {
    1 => [
        'name' => ['required', 'string', 'max:150'],
        'slug' => ['required', 'string', new SchoolSlug, Rule::unique('schools', 'slug')],
        'email' => ['nullable', 'email', 'max:255'],
        'phone' => ['nullable', 'string', 'max:30'],
        'address' => ['nullable', 'string', 'max:500'],
        'city' => ['nullable', 'string', 'max:100'],
        'country' => ['nullable', 'string', 'max:100'],
    ],
    2 => [
        'plan_id' => ['required', Rule::exists('plans', 'id')->whereNull('deleted_at')->where('is_active', true)],
        'billing' => ['required', Rule::in(['trial', 'paid', 'open'])],
        'trial_ends_at' => ['exclude_unless:billing,trial', 'required', 'date', 'after_or_equal:today'],
        'amount' => ['exclude_unless:billing,paid', 'required', 'numeric', 'min:0', 'max:99999999'],
        'period_start' => ['exclude_unless:billing,paid', 'required', 'date'],
        'period_end' => ['exclude_unless:billing,paid', 'required', 'date', 'after_or_equal:period_start'],
        'paid_on' => ['exclude_unless:billing,paid', 'required', 'date', 'before_or_equal:today'],
        'payment_method' => ['exclude_unless:billing,paid', 'required', Rule::enum(PaymentMethod::class)],
        'reference' => ['exclude_unless:billing,paid', 'nullable', 'string', 'max:100'],
        'currency_code' => ['required', Rule::in(array_keys(LocaleOptions::CURRENCIES))],
        'currency_symbol' => ['required', 'string', 'max:8'],
        'timezone' => ['required', 'timezone:all'],
        'date_format' => ['required', Rule::in(LocaleOptions::DATE_FORMATS)],
    ],
    3 => [
        'admin_name' => ['required', 'string', 'max:100'],
        'admin_username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
        'admin_email' => ['nullable', 'email', 'max:255'],
        'admin_phone' => ['nullable', 'string', 'max:30'],
        'admin_password' => ['required', 'string', Password::defaults()],
    ],
    default => [],
});

$attributesFor = protect(fn () => [
    'slug' => __('address'),
    'plan_id' => __('plan'),
    'trial_ends_at' => __('trial end date'),
    'period_start' => __('period start'),
    'period_end' => __('period end'),
    'paid_on' => __('payment date'),
    'payment_method' => __('payment method'),
    'currency_code' => __('currency'),
    'currency_symbol' => __('currency symbol'),
    'date_format' => __('date format'),
    'admin_name' => __('name'),
    'admin_username' => __('username'),
    'admin_email' => __('email'),
    'admin_phone' => __('phone'),
    'admin_password' => __('password'),
]);

// A new step replaces the form, so start reading it from the top.
$scrollToTop = protect(fn () => $this->js('window.scrollTo({ top: 0, behavior: "smooth" })'));

$next = function () {
    $this->validate($this->rulesFor($this->step), [], $this->attributesFor());
    $this->step = min(4, $this->step + 1);
    $this->scrollToTop();
};

$back = function () {
    $this->resetValidation();
    $this->step = max(1, $this->step - 1);
    $this->scrollToTop();
};

$goTo = function (int $step) {
    // Only steps already completed can be revisited.
    if ($step >= 1 && $step < $this->step) {
        $this->resetValidation();
        $this->step = $step;
        $this->scrollToTop();
    }
};

$regeneratePassword = function () {
    $this->admin_password = Passwords::generate();
};

$submit = function () {
    $this->step < 4 ? $this->next() : $this->create();
};

$create = function () {
    $this->authorize('manage-schools');

    // Re-check everything: earlier steps may have changed since they were validated.
    foreach ([1, 2, 3] as $step) {
        try {
            $this->validate($this->rulesFor($step), [], $this->attributesFor());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->step = $step;

            throw $e;
        }
    }

    $result = app(ProvisionSchool::class)->handle(
        school: [
            'name' => $this->name,
            'slug' => $this->slug,
            'email' => $this->email ?: null,
            'phone' => $this->phone ?: null,
            'address' => $this->address ?: null,
            'city' => $this->city ?: null,
            'country' => $this->country ?: null,
            'plan_id' => (int) $this->plan_id,
            'trial_ends_at' => $this->billing === 'trial' ? $this->trial_ends_at : null,
            'settings' => [
                'currency_code' => $this->currency_code,
                'currency_symbol' => $this->currency_symbol,
                'timezone' => $this->timezone,
                'date_format' => $this->date_format,
            ],
        ],
        admin: [
            'name' => $this->admin_name,
            'username' => $this->admin_username,
            'email' => $this->admin_email ?: null,
            'phone' => $this->admin_phone ?: null,
        ],
        password: $this->admin_password,
        payment: $this->billing === 'paid' ? [
            'plan_id' => (int) $this->plan_id,
            'amount' => $this->amount,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'paid_on' => $this->paid_on,
            'payment_method' => $this->payment_method,
            'reference' => $this->reference ?: null,
        ] : null,
        by: auth()->user(),
    );

    $this->created = [
        'name' => $result->school->name,
        'slug' => $result->school->slug,
        'url' => $result->school->loginUrl(),
        'username' => $result->admin->username,
        'password' => $result->password,
    ];

    $this->admin_password = '';
    $this->toast(__(':name is ready.', ['name' => $result->school->name]));
};

$startOver = function () {
    $this->redirectRoute('central.schools.create', navigate: true);
};

?>

@php
    $billingLabels = [
        'trial' => __('Free trial'),
        'paid' => __('Paid: record the first payment'),
        'open' => __('No end date'),
    ];
@endphp

<div>
    <x-page-header :title="__('New school')" :breadcrumbs="[__('Super Admin') => route('central.dashboard'), __('Schools') => route('central.schools.index'), __('New school') => null]" />

    @if ($created)
        <div class="row justify-content-center">
            <div class="col-xl-7 col-lg-9">
                <div class="card mb-0">
                    <div class="card-body p-4 p-sm-5">
                        <div class="text-center mb-4">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success-subtle text-success mb-3" style="width: 4rem; height: 4rem">
                                <i class="ri-check-double-line fs-2"></i>
                            </span>
                            <h4 class="fw-semibold mb-1">{{ __(':name is ready', ['name' => $created['name']]) }}</h4>
                            <p class="text-muted mb-0">{{ __('Send these sign-in details to the school\'s administrator.') }}</p>
                        </div>

                        <x-credentials :url="$created['url']" :username="$created['username']" :password="$created['password']" />

                        <hr class="my-4">

                        <div class="d-flex flex-wrap gap-2 justify-content-center">
                            <a href="{{ route('central.schools.show', $created['slug']) }}" wire:navigate class="btn btn-primary">
                                <i class="ri-building-4-line me-1"></i>{{ __('Open school') }}
                            </a>
                            <button type="button" class="btn btn-light" wire:click="startOver">
                                <i class="ri-add-line me-1"></i>{{ __('Create another') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="row g-4">
            <div class="col-xl-8">
                <form wire:submit="submit" novalidate class="card mb-0">
                    <div class="card-header">
                        <ol class="wizard-steps" aria-label="{{ __('Steps') }}">
                            @foreach ($this->steps as $number => $label)
                                <li @class(['is-current' => $number === $step, 'is-done' => $number < $step]) @if ($number === $step) aria-current="step" @endif>
                                    @if ($number < $step)
                                        <button type="button" class="btn btn-link p-0 text-reset text-decoration-none d-flex align-items-center gap-2" wire:click="goTo({{ $number }})">
                                            <span class="wizard-step-number"><i class="ri-check-line"></i></span>{{ $label }}
                                        </button>
                                    @else
                                        <span class="wizard-step-number">{{ $number }}</span>{{ $label }}
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </div>

                    <div class="card-body">
                        @if ($step === 1)
                            <h5 class="mb-1">{{ __('About the school') }}</h5>
                            <p class="text-muted mb-4">{{ __('The address becomes part of every link the school uses, so keep it short.') }}</p>

                            <x-form.input name="name" :label="__('School name')" required maxlength="150" live autofocus :placeholder="__('e.g. Greenfield Academy')" />

                            <div class="mb-4">
                                <label class="form-label" for="field-slug">{{ __('Address') }}<span class="text-danger ms-1">*</span></label>
                                <div class="input-group has-validation">
                                    <span class="input-group-text text-muted">{{ Str::finish(preg_replace('#^https?://#', '', url('/')), '/') }}</span>
                                    <input type="text" id="field-slug" wire:model.live.debounce.400ms="slug" required aria-required="true" maxlength="60"
                                           autocomplete="off" spellcheck="false" aria-describedby="field-slug-hint @error('slug') field-slug-error @enderror"
                                           @class(['form-control', 'is-invalid' => $errors->has('slug'), 'is-valid' => $slugTouched && filled($slug) && ! $errors->has('slug')])>
                                    @error('slug')
                                        <div class="invalid-feedback" id="field-slug-error">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="form-text" id="field-slug-hint">{{ __('Lowercase letters, numbers and hyphens. Sign-in page: :url', ['url' => url('/'.($slug ?: 'your-school').'/login')]) }}</div>
                            </div>

                            <div class="row">
                                <div class="col-sm-6"><x-form.input name="email" type="email" :label="__('Office email')" maxlength="255" autocomplete="off" /></div>
                                <div class="col-sm-6"><x-form.input name="phone" type="tel" :label="__('Office phone')" maxlength="30" autocomplete="off" /></div>
                                <div class="col-sm-6"><x-form.input name="city" :label="__('City')" maxlength="100" /></div>
                                <div class="col-sm-6"><x-form.input name="country" :label="__('Country')" maxlength="100" /></div>
                            </div>
                            <x-form.textarea name="address" :label="__('Street address')" rows="2" maxlength="500" wrapper="mb-0" />
                        @elseif ($step === 2)
                            <h5 class="mb-1">{{ __('Plan') }}</h5>
                            <p class="text-muted mb-3">{{ __('Sets the school\'s limits and features. You can change it later.') }}</p>

                            @if ($this->plans->isEmpty())
                                <div class="alert alert-warning">
                                    {{ __('There are no plans on offer.') }}
                                    <a href="{{ route('central.plans.index') }}" wire:navigate class="alert-link">{{ __('Create a plan first.') }}</a>
                                </div>
                            @else
                                <div class="row g-3 mb-2" role="radiogroup" aria-label="{{ __('Plan') }}">
                                    @foreach ($this->plans as $option)
                                        <div class="col-md-4" wire:key="plan-{{ $option->id }}">
                                            <label class="option-card">
                                                <input type="radio" class="form-check-input" name="plan" value="{{ $option->id }}" wire:model.live="plan_id">
                                                <span class="d-block fw-semibold text-body pe-4">{{ $option->name }}</span>
                                                <span class="d-block fs-5 fw-semibold text-primary mt-1">{{ $option->formattedPrice() }}
                                                    <small class="fs-12 text-muted fw-normal">{{ $option->billing_cycle->per() }}</small></span>
                                                <span class="d-block text-muted fs-13 mt-2">
                                                    {{ $option->max_students === null ? __('Unlimited students') : __(':count students', ['count' => number_format($option->max_students)]) }}
                                                    · {{ $option->max_staff === null ? __('unlimited staff') : __(':count staff', ['count' => number_format($option->max_staff)]) }}
                                                </span>
                                                <span class="d-block text-muted fs-13">
                                                    {{ $option->includesAllFeatures() ? __('All features') : trans_choice('{0} Core features only|{1} Core + :count feature|[2,*] Core + :count features', count($option->features), ['count' => count($option->features)]) }}
                                                </span>
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                                @error('plan_id') <div class="text-danger fs-13 mb-3">{{ $message }}</div> @enderror
                            @endif

                            <h5 class="mt-4 mb-3">{{ __('Billing') }}</h5>
                            <div class="d-flex flex-wrap gap-3 mb-3" role="radiogroup" aria-label="{{ __('Billing') }}">
                                @foreach ($billingLabels as $value => $label)
                                    <div class="form-check">
                                        <input type="radio" class="form-check-input" id="billing-{{ $value }}" value="{{ $value }}" wire:model.live="billing">
                                        <label class="form-check-label" for="billing-{{ $value }}">{{ $label }}</label>
                                    </div>
                                @endforeach
                            </div>

                            @if ($billing === 'trial')
                                <div class="row">
                                    <div class="col-sm-6">
                                        <x-form.input name="trial_ends_at" type="date" :label="__('Trial ends on')" required :min="today()->toDateString()"
                                                      :hint="__('The school is suspended :days days after this unless a payment is recorded.', ['days' => config('plans.subscriptions.grace_days')])" />
                                    </div>
                                </div>
                            @elseif ($billing === 'paid')
                                <div class="row">
                                    <div class="col-sm-4"><x-form.input name="amount" type="number" step="0.01" min="0" :label="__('Amount (:currency)', ['currency' => Money::currency()])" required /></div>
                                    <div class="col-sm-4"><x-form.input name="period_start" type="date" :label="__('Covers from')" required live /></div>
                                    <div class="col-sm-4"><x-form.input name="period_end" type="date" :label="__('Covers until')" required /></div>
                                    <div class="col-sm-4"><x-form.input name="paid_on" type="date" :label="__('Paid on')" required :max="today()->toDateString()" /></div>
                                    <div class="col-sm-4"><x-form.select name="payment_method" :label="__('Method')" :options="PaymentMethod::options()" :placeholder="null" required /></div>
                                    <div class="col-sm-4"><x-form.input name="reference" :label="__('Reference')" maxlength="100" :placeholder="__('Receipt or transfer no.')" /></div>
                                </div>
                            @else
                                <p class="text-muted fs-13">{{ __('The school never expires on its own. Use this for internal or complimentary schools.') }}</p>
                            @endif

                            <h5 class="mt-3 mb-1">{{ __('Regional settings') }}</h5>
                            <p class="text-muted mb-3">{{ __('Used for fees, dates and times. The school admin can change them later.') }}</p>
                            <div class="row">
                                <div class="col-sm-6 col-lg-3"><x-form.select name="currency_code" :label="__('Currency')" :options="LocaleOptions::currencies()" :placeholder="null" required live /></div>
                                <div class="col-sm-6 col-lg-3"><x-form.input name="currency_symbol" :label="__('Symbol')" required maxlength="8" /></div>
                                <div class="col-sm-6 col-lg-3"><x-form.select name="timezone" :label="__('Timezone')" :options="LocaleOptions::timezones()" :placeholder="null" required /></div>
                                <div class="col-sm-6 col-lg-3"><x-form.select name="date_format" :label="__('Date format')" :options="LocaleOptions::dateFormats()" :placeholder="null" required wrapper="mb-0" /></div>
                            </div>
                        @elseif ($step === 3)
                            <h5 class="mb-1">{{ __('First administrator') }}</h5>
                            <p class="text-muted mb-4">{{ __('This account gets full access to the school and must choose a new password at first sign-in.') }}</p>

                            <div class="row">
                                <div class="col-sm-6"><x-form.input name="admin_name" :label="__('Full name')" required maxlength="100" autocomplete="off" /></div>
                                <div class="col-sm-6"><x-form.input name="admin_username" :label="__('Username')" required maxlength="60" autocomplete="off" spellcheck="false"
                                                                    :hint="__('Letters, numbers, dots, dashes and underscores.')" /></div>
                                <div class="col-sm-6"><x-form.input name="admin_email" type="email" :label="__('Email (optional)')" maxlength="255" autocomplete="off"
                                                                    :hint="__('Can also be used to sign in.')" /></div>
                                <div class="col-sm-6"><x-form.input name="admin_phone" type="tel" :label="__('Phone (optional)')" maxlength="30" autocomplete="off"
                                                                    :hint="__('Can also be used to sign in.')" /></div>
                            </div>

                            <label class="form-label" for="field-admin-password">{{ __('Temporary password') }}<span class="text-danger ms-1">*</span></label>
                            <div class="input-group has-validation">
                                <input type="text" id="field-admin-password" wire:model="admin_password" required autocomplete="off" spellcheck="false"
                                       @class(['form-control font-monospace', 'is-invalid' => $errors->has('admin_password')])
                                       @error('admin_password') aria-invalid="true" aria-describedby="field-admin-password-error" @enderror>
                                <button type="button" class="btn btn-light" wire:click="regeneratePassword">
                                    <i class="ri-refresh-line me-1"></i>{{ __('Generate') }}
                                </button>
                                @error('admin_password')
                                    <div class="invalid-feedback" id="field-admin-password-error">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="form-text">{{ __('Shown once more after the school is created. At least 8 characters with letters and numbers.') }}</div>
                        @else
                            <h5 class="mb-1">{{ __('Review') }}</h5>
                            <p class="text-muted mb-4">{{ __('Check the details, then create the school. It is ready to use straight away.') }}</p>

                            <div class="row g-4">
                                <div class="col-md-6">
                                    <h6 class="text-uppercase text-muted fs-12 mb-3">{{ __('School') }}</h6>
                                    <dl class="detail-list">
                                        <dt>{{ __('Name') }}</dt><dd>{{ $name }}</dd>
                                        <dt>{{ __('Sign-in page') }}</dt><dd>{{ url('/'.$slug.'/login') }}</dd>
                                        <dt>{{ __('Email') }}</dt><dd>{{ $email ?: '—' }}</dd>
                                        <dt>{{ __('Phone') }}</dt><dd>{{ $phone ?: '—' }}</dd>
                                        <dt>{{ __('Location') }}</dt><dd>{{ collect([$city, $country])->filter()->implode(', ') ?: '—' }}</dd>
                                    </dl>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-uppercase text-muted fs-12 mb-3">{{ __('Plan & billing') }}</h6>
                                    <dl class="detail-list">
                                        <dt>{{ __('Plan') }}</dt><dd>{{ $this->plan?->name }} ({{ $this->plan?->formattedPrice() }} {{ $this->plan?->billing_cycle->per() }})</dd>
                                        <dt>{{ __('Billing') }}</dt>
                                        <dd>
                                            @if ($billing === 'trial')
                                                {{ __('Trial until :date', ['date' => Carbon::parse($trial_ends_at)->format('d M Y')]) }}
                                            @elseif ($billing === 'paid')
                                                {{ __(':amount paid, covering :start – :end', ['amount' => Money::platform($amount), 'start' => Carbon::parse($period_start)->format('d M Y'), 'end' => Carbon::parse($period_end)->format('d M Y')]) }}
                                            @else
                                                {{ __('No end date') }}
                                            @endif
                                        </dd>
                                        <dt>{{ __('Regional') }}</dt><dd>{{ $currency_code }} ({{ $currency_symbol }}) · {{ $timezone }} · {{ today()->format($date_format) }}</dd>
                                    </dl>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-uppercase text-muted fs-12 mb-3">{{ __('Administrator') }}</h6>
                                    <dl class="detail-list mb-0">
                                        <dt>{{ __('Name') }}</dt><dd>{{ $admin_name }}</dd>
                                        <dt>{{ __('Username') }}</dt><dd>{{ $admin_username }}</dd>
                                        <dt>{{ __('Email') }}</dt><dd>{{ $admin_email ?: '—' }}</dd>
                                        <dt>{{ __('Phone') }}</dt><dd>{{ $admin_phone ?: '—' }}</dd>
                                    </dl>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-uppercase text-muted fs-12 mb-3">{{ __('Also created') }}</h6>
                                    <ul class="list-unstyled mb-0 vstack gap-2">
                                        <li><i class="ri-checkbox-circle-line text-success me-2"></i>{{ __('Built-in roles: School Admin, Teacher, HR, Accountant, Librarian, Student, Parent') }}</li>
                                        <li><i class="ri-checkbox-circle-line text-success me-2"></i>{{ __('A Main Campus') }}</li>
                                    </ul>
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="card-footer d-flex justify-content-between gap-2">
                        @if ($step > 1)
                            <button type="button" class="btn btn-light" wire:click="back"><i class="ri-arrow-left-line me-1"></i>{{ __('Back') }}</button>
                        @else
                            <a href="{{ route('central.schools.index') }}" wire:navigate class="btn btn-light">{{ __('Cancel') }}</a>
                        @endif

                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="submit,next,create">
                            @if ($step < 4)
                                {{ __('Continue') }}<i class="ri-arrow-right-line ms-1"></i>
                            @else
                                <span wire:loading.remove wire:target="submit,create"><i class="ri-check-line me-1"></i>{{ __('Create school') }}</span>
                                <span wire:loading wire:target="submit,create"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Creating…') }}</span>
                            @endif
                        </button>
                    </div>
                </form>
            </div>

            <div class="col-xl-4">
                <div class="card mb-0">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <span class="entity-avatar entity-avatar-lg">{{ Str::upper(Str::substr($name ?: '?', 0, 1)) }}</span>
                            <div class="min-w-0">
                                <h5 class="mb-0 text-truncate">{{ $name ?: __('New school') }}</h5>
                                <small class="text-muted text-truncate d-block">/{{ $slug ?: 'your-school' }}</small>
                            </div>
                        </div>
                        <dl class="detail-list fs-13 mb-0">
                            <dt>{{ __('Plan') }}</dt><dd>{{ $this->plan?->name ?? '—' }}</dd>
                            <dt>{{ __('Billing') }}</dt><dd>{{ $billingLabels[$billing] ?? '—' }}</dd>
                            <dt>{{ __('Admin') }}</dt><dd>{{ $admin_username ?: '—' }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
