<?php

/*
 * School settings: profile and logo, regional formats, document numbering
 * and the fee options later phases read (discounts, sibling discount, bulk
 * invoices, late fees). Stored in schools.settings over the defaults in
 * config('tenancy.default_settings').
 */

use App\Enums\LateFeeType;
use App\Livewire\Concerns\InteractsWithUi;
use App\Support\Locale\LocaleOptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;

use function Livewire\Volt\{layout, mount, protect, state, title, uses};

layout('layouts.app');
title(__('School settings'));

uses([InteractsWithUi::class, WithFileUploads::class]);

state(['tab' => 'general', 'canEdit' => false]);

state([
    // General
    'name' => '', 'email' => '', 'phone' => '', 'address' => '', 'city' => '', 'country' => '',
    'logo' => null, 'remove_logo' => false,
    // Regional
    'currency_code' => '', 'currency_symbol' => '', 'timezone' => '', 'date_format' => '', 'time_format' => '', 'week_starts_on' => '1',
    // Attendance (ISO weekdays, as strings for the checkboxes)
    'weekly_off' => [],
    // Numbering
    'admission_prefix' => '', 'employee_prefix' => '', 'invoice_prefix' => '', 'receipt_prefix' => '',
    // Fees
    'discounts_enabled' => true, 'sibling_discount_enabled' => false, 'bulk_generation_enabled' => true,
    'late_fee_enabled' => false, 'late_fee_type' => 'fixed', 'late_fee_amount' => '0', 'late_fee_grace_days' => '0', 'late_fee_max_amount' => '',
]);

mount(function () {
    $this->authorize('settings.view');
    $this->canEdit = auth()->user()->can('settings.edit');

    $school = tenant();

    $this->fill([
        ...$school->only(['name', 'email', 'phone', 'address', 'city', 'country']),
        'currency_code' => $school->setting('currency_code'),
        'currency_symbol' => $school->setting('currency_symbol'),
        'timezone' => $school->setting('timezone'),
        'date_format' => $school->setting('date_format'),
        'time_format' => $school->setting('time_format'),
        'week_starts_on' => (string) $school->setting('week_starts_on'),
        'weekly_off' => array_map('strval', app(\App\Services\Attendance\SchoolCalendar::class)->weeklyOff()),
        'admission_prefix' => $school->setting('numbering.admission_prefix'),
        'employee_prefix' => $school->setting('numbering.employee_prefix'),
        'invoice_prefix' => $school->setting('numbering.invoice_prefix'),
        'receipt_prefix' => $school->setting('numbering.receipt_prefix'),
        'discounts_enabled' => (bool) $school->setting('fees.discounts_enabled'),
        'sibling_discount_enabled' => (bool) $school->setting('fees.sibling_discount_enabled'),
        'bulk_generation_enabled' => (bool) $school->setting('fees.bulk_generation_enabled'),
        'late_fee_enabled' => (bool) $school->setting('fees.late_fee.enabled'),
        'late_fee_type' => $school->setting('fees.late_fee.type'),
        'late_fee_amount' => (string) $school->setting('fees.late_fee.amount'),
        'late_fee_grace_days' => (string) $school->setting('fees.late_fee.grace_days'),
        'late_fee_max_amount' => (string) ($school->setting('fees.late_fee.max_amount') ?? ''),
    ]);
});

$ruleGroups = protect(fn () => [
    'general' => [
        'name' => ['required', 'string', 'max:150'],
        'email' => ['nullable', 'email', 'max:255'],
        'phone' => ['nullable', 'string', 'max:30'],
        'address' => ['nullable', 'string', 'max:500'],
        'city' => ['nullable', 'string', 'max:100'],
        'country' => ['nullable', 'string', 'max:100'],
        'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:min_width=64,min_height=64'],
        'remove_logo' => ['boolean'],
    ],
    'regional' => [
        'currency_code' => ['required', Rule::in(array_keys(LocaleOptions::CURRENCIES))],
        'currency_symbol' => ['required', 'string', 'max:8'],
        'timezone' => ['required', 'timezone:all'],
        'date_format' => ['required', Rule::in(LocaleOptions::DATE_FORMATS)],
        'time_format' => ['required', Rule::in(LocaleOptions::TIME_FORMATS)],
        'week_starts_on' => ['required', Rule::in(array_map('strval', array_keys(LocaleOptions::weekStarts())))],
    ],
    'attendance' => [
        'weekly_off' => ['array', 'max:6'],
        'weekly_off.*' => ['integer', 'between:1,7'],
    ],
    'numbering' => [
        'admission_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9\/_-]*$/'],
        'employee_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9\/_-]*$/'],
        'invoice_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9\/_-]*$/'],
        'receipt_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9\/_-]*$/'],
    ],
    'fees' => [
        'discounts_enabled' => ['boolean'],
        'sibling_discount_enabled' => ['boolean'],
        'bulk_generation_enabled' => ['boolean'],
        'late_fee_enabled' => ['boolean'],
        'late_fee_type' => ['required', Rule::enum(LateFeeType::class)],
        'late_fee_amount' => ['required', 'numeric', 'min:0', 'max:99999999', ...($this->late_fee_type === 'percentage' ? ['max:100'] : [])],
        'late_fee_grace_days' => ['required', 'integer', 'min:0', 'max:365'],
        'late_fee_max_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
    ],
]);

$save = function () {
    $this->authorize('settings.edit');

    $groups = $this->ruleGroups();

    try {
        $data = $this->validate(array_merge(...array_values($groups)), [
            'admission_prefix.regex' => __('Use letters, numbers, slashes, dashes or underscores.'),
            'employee_prefix.regex' => __('Use letters, numbers, slashes, dashes or underscores.'),
            'invoice_prefix.regex' => __('Use letters, numbers, slashes, dashes or underscores.'),
            'receipt_prefix.regex' => __('Use letters, numbers, slashes, dashes or underscores.'),
        ]);
    } catch (ValidationException $e) {
        // Show the first tab that has a problem.
        $first = array_key_first($e->errors());
        $this->tab = collect($groups)->search(fn ($fields) => array_key_exists($first, $fields)) ?: $this->tab;

        throw $e;
    }

    $school = tenant();
    $blank = fn ($value) => $value === '' ? null : $value;

    $weeklyOff = collect($data['weekly_off'])->map(fn ($day) => (int) $day)->unique()->sort()->implode(',');

    $attributes = [
        'name' => $data['name'],
        'email' => $blank($data['email']),
        'phone' => $blank($data['phone']),
        'address' => $blank($data['address']),
        'city' => $blank($data['city']),
        'country' => $blank($data['country']),
        'settings' => array_replace_recursive($school->settings ?? [], [
            'currency_code' => $data['currency_code'],
            'currency_symbol' => $data['currency_symbol'],
            'timezone' => $data['timezone'],
            'date_format' => $data['date_format'],
            'time_format' => $data['time_format'],
            'week_starts_on' => (int) $data['week_starts_on'],
            'attendance' => ['weekly_off' => $weeklyOff],
            'numbering' => [
                'admission_prefix' => (string) $data['admission_prefix'],
                'employee_prefix' => (string) $data['employee_prefix'],
                'invoice_prefix' => (string) $data['invoice_prefix'],
                'receipt_prefix' => (string) $data['receipt_prefix'],
            ],
            'fees' => [
                'discounts_enabled' => (bool) $data['discounts_enabled'],
                'sibling_discount_enabled' => (bool) $data['sibling_discount_enabled'],
                'bulk_generation_enabled' => (bool) $data['bulk_generation_enabled'],
                'late_fee' => [
                    'enabled' => (bool) $data['late_fee_enabled'],
                    'type' => $data['late_fee_type'],
                    'amount' => (float) $data['late_fee_amount'],
                    'grace_days' => (int) $data['late_fee_grace_days'],
                    'max_amount' => $blank($data['late_fee_max_amount']) === null ? null : (float) $data['late_fee_max_amount'],
                ],
            ],
        ]),
    ];

    if ($data['remove_logo'] || $this->logo) {
        if ($school->logo) {
            Storage::disk('public')->delete($school->logo);
        }

        $attributes['logo'] = $this->logo
            ? $this->logo->storeAs("schools/{$school->id}", 'logo-'.Str::lower(Str::random(8)).'.'.strtolower($this->logo->getClientOriginalExtension()), 'public')
            : null;
    }

    $school->update($attributes);

    activity('settings')->performedOn($school)->causedBy(auth()->user())
        ->withProperties(['changed' => array_keys($school->getChanges())])
        ->log('School settings updated');

    $this->reset('logo', 'remove_logo');

    // The layout (name, logo) and formats shown everywhere come from these settings.
    $this->flashToast(__('Settings saved.'));
    $this->redirectRoute('admin.settings.index', navigate: true);
};

?>

@php
    $tabs = [
        'general' => [__('School profile'), 'ri-building-4-line'],
        'regional' => [__('Regional'), 'ri-global-line'],
        'attendance' => [__('Attendance'), 'ri-calendar-check-line'],
        'numbering' => [__('Numbering'), 'ri-hashtag'],
        'fees' => [__('Fee options'), 'ri-hand-coin-line'],
    ];
    $tabErrors = [
        'general' => ['name', 'email', 'phone', 'address', 'city', 'country', 'logo'],
        'regional' => ['currency_code', 'currency_symbol', 'timezone', 'date_format', 'time_format', 'week_starts_on'],
        'attendance' => ['weekly_off', 'weekly_off.0'],
        'numbering' => ['admission_prefix', 'employee_prefix', 'invoice_prefix', 'receipt_prefix'],
        'fees' => ['late_fee_type', 'late_fee_amount', 'late_fee_grace_days', 'late_fee_max_amount'],
    ];
    $school = tenant();
    $symbol = $currency_symbol ?: $school->setting('currency_symbol');
@endphp

<div>
    <x-page-header :title="__('School settings')" :breadcrumbs="[__('School Admin') => route('admin.dashboard'), __('Administration') => null, __('School settings') => null]" />

    @unless ($canEdit)
        <div class="alert alert-light border d-flex gap-2"><i class="ri-eye-line fs-5 lh-1"></i>{{ __('You can view these settings but not change them.') }}</div>
    @endunless

    <form wire:submit="save" class="row g-4" novalidate>
        <div class="col-lg-3">
            <div class="card mb-0">
                <div class="list-group list-group-flush" role="tablist" aria-orientation="vertical">
                    @foreach ($tabs as $key => [$label, $icon])
                        @php($hasError = collect($tabErrors[$key])->contains(fn ($field) => $errors->has($field)))
                        <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                                @class(['list-group-item list-group-item-action d-flex align-items-center gap-2', 'active' => $tab === $key])>
                            <i class="{{ $icon }}"></i><span class="flex-grow-1 text-start">{{ $label }}</span>
                            @if ($hasError)
                                <i class="ri-error-warning-fill text-danger" title="{{ __('Has errors') }}"></i>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="col-lg-9">
            <div class="card mb-0">
                <fieldset @disabled(! $canEdit)>
                    <div class="card-body">
                        @if ($tab === 'general')
                            <h5 class="mb-1">{{ __('School profile') }}</h5>
                            <p class="text-muted mb-4">{{ __('Shown in the sidebar, on sign-in pages and on printed documents.') }}</p>

                            <div class="d-flex flex-wrap align-items-center gap-4 mb-4">
                                <span class="brand-mark brand-mark-lg">
                                    @if ($logo && ! $errors->has('logo'))
                                        <img src="{{ $logo->temporaryUrl() }}" alt="{{ __('New logo preview') }}">
                                    @elseif ($school->logoUrl() && ! $remove_logo)
                                        <img src="{{ $school->logoUrl() }}" alt="{{ __('Current logo') }}">
                                    @else
                                        {{ Str::upper(Str::substr($school->name, 0, 1)) }}
                                    @endif
                                </span>
                                <div>
                                    <label class="btn btn-sm btn-light mb-0" for="field-logo"><i class="ri-upload-2-line me-1"></i>{{ __('Upload logo') }}</label>
                                    <input type="file" id="field-logo" class="d-none" wire:model="logo" accept="image/png,image/jpeg,image/webp">
                                    @if (($school->logo && ! $remove_logo) || $logo)
                                        <button type="button" class="btn btn-sm btn-link text-danger" wire:click="$set('remove_logo', true); $set('logo', null)">{{ __('Remove') }}</button>
                                    @endif
                                    <div class="form-text mt-1">{{ __('PNG, JPG or WebP, at least 64 × 64 px, up to 2 MB. Square logos look best.') }}</div>
                                    <div wire:loading wire:target="logo" class="form-text"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Uploading…') }}</div>
                                    @error('logo') <div class="text-danger fs-13">{{ $message }}</div> @enderror
                                </div>
                            </div>

                            <x-form.input name="name" :label="__('School name')" required maxlength="150" />
                            <div class="row">
                                <div class="col-sm-6"><x-form.input name="email" type="email" :label="__('Email')" maxlength="255" /></div>
                                <div class="col-sm-6"><x-form.input name="phone" type="tel" :label="__('Phone')" maxlength="30" /></div>
                                <div class="col-sm-6"><x-form.input name="city" :label="__('City')" maxlength="100" /></div>
                                <div class="col-sm-6"><x-form.input name="country" :label="__('Country')" maxlength="100" /></div>
                            </div>
                            <x-form.textarea name="address" :label="__('Address')" rows="2" maxlength="500" wrapper="mb-0" />
                        @elseif ($tab === 'regional')
                            <h5 class="mb-1">{{ __('Regional') }}</h5>
                            <p class="text-muted mb-4">{{ __('How money, dates and times are shown across the school.') }}</p>
                            <div class="row">
                                <div class="col-sm-6"><x-form.select name="currency_code" :label="__('Currency')" :options="LocaleOptions::currencies()" :placeholder="false" required /></div>
                                <div class="col-sm-6"><x-form.input name="currency_symbol" :label="__('Currency symbol')" required maxlength="8" /></div>
                                <div class="col-sm-6"><x-form.select name="timezone" :label="__('Timezone')" :options="LocaleOptions::timezones()" :placeholder="false" required /></div>
                                <div class="col-sm-6"><x-form.select name="week_starts_on" :label="__('Week starts on')" :options="LocaleOptions::weekStarts()" :placeholder="false" required /></div>
                                <div class="col-sm-6"><x-form.select name="date_format" :label="__('Date format')" :options="LocaleOptions::dateFormats()" :placeholder="false" required wrapper="mb-0" /></div>
                                <div class="col-sm-6"><x-form.select name="time_format" :label="__('Time format')" :options="LocaleOptions::timeFormats()" :placeholder="false" required wrapper="mb-0" /></div>
                            </div>
                        @elseif ($tab === 'attendance')
                            <h5 class="mb-1">{{ __('Attendance') }}</h5>
                            <p class="text-muted mb-4">{{ __('Weekly off days aren\'t counted as school days in leave requests and attendance reports.') }}</p>

                            <fieldset>
                                <legend class="form-label fs-14">{{ __('Weekly off days') }}</legend>
                                <div class="d-flex flex-wrap gap-2">
                                    @foreach (LocaleOptions::weekdays() as $day => $dayName)
                                        <input type="checkbox" class="btn-check" id="weekly-off-{{ $day }}" value="{{ $day }}" wire:model="weekly_off" autocomplete="off">
                                        <label class="btn btn-sm btn-outline-primary" for="weekly-off-{{ $day }}">{{ $dayName }}</label>
                                    @endforeach
                                </div>
                                @error('weekly_off') <div class="text-danger fs-13 mt-1">{{ $message }}</div> @enderror
                                <div class="form-text">{{ __('At least one day must be a school day.') }}</div>
                            </fieldset>
                        @elseif ($tab === 'numbering')
                            <h5 class="mb-1">{{ __('Numbering') }}</h5>
                            <p class="text-muted mb-4">{{ __('Prefixes for numbers the system gives out. Existing numbers don\'t change.') }}</p>
                            <div class="row">
                                <div class="col-sm-6"><x-form.input name="admission_prefix" :label="__('Admission numbers')" maxlength="10" :hint="__('e.g. :example', ['example' => ($admission_prefix ?: '').'0001'])" /></div>
                                <div class="col-sm-6"><x-form.input name="employee_prefix" :label="__('Employee IDs')" maxlength="10" :hint="__('e.g. :example', ['example' => ($employee_prefix ?: '').'0001'])" /></div>
                                <div class="col-sm-6"><x-form.input name="invoice_prefix" :label="__('Invoices')" maxlength="10" wrapper="mb-0" :hint="__('e.g. :example', ['example' => ($invoice_prefix ?: '').now()->year.'-0001'])" /></div>
                                <div class="col-sm-6"><x-form.input name="receipt_prefix" :label="__('Receipts')" maxlength="10" wrapper="mb-0" :hint="__('e.g. :example', ['example' => ($receipt_prefix ?: '').now()->year.'-0001'])" /></div>
                            </div>
                        @else
                            <h5 class="mb-1">{{ __('Fee options') }}</h5>
                            <p class="text-muted mb-4">{{ __('Switch fee features on or off for this school. They take effect in the Accounting portal.') }}</p>

                            <x-form.switch name="discounts_enabled" :label="__('Discounts and scholarships')" :hint="__('Give individual students a fixed or percentage reduction.')" />
                            <x-form.switch name="sibling_discount_enabled" :label="__('Sibling discount')" :hint="__('Reduce fees automatically for the second, third... child of the same parent.')" />
                            <x-form.switch name="bulk_generation_enabled" :label="__('Bulk monthly invoices')" :hint="__('Generate a month\'s invoices for a whole class or school in one go.')" />

                            <hr>

                            <x-form.switch name="late_fee_enabled" :label="__('Late fee fines')" live :hint="__('Added to invoices that are still unpaid after the due date.')" />

                            @if ($late_fee_enabled)
                                <div class="row">
                                    <div class="col-sm-6"><x-form.select name="late_fee_type" :label="__('Charged as')" :options="LateFeeType::options()" :placeholder="false" required live /></div>
                                    <div class="col-sm-6">
                                        <x-form.input name="late_fee_amount" type="number" step="0.01" min="0" required
                                                      :label="match ($late_fee_type) { 'percentage' => __('Percentage of the invoice'), 'daily' => __('Amount per day (:symbol)', ['symbol' => $symbol]), default => __('Amount (:symbol)', ['symbol' => $symbol]) }" />
                                    </div>
                                    <div class="col-sm-6"><x-form.input name="late_fee_grace_days" type="number" min="0" max="365" required :label="__('Days of grace')" wrapper="mb-0"
                                                                        :hint="__('Days after the due date before the fine starts.')" /></div>
                                    <div class="col-sm-6"><x-form.input name="late_fee_max_amount" type="number" step="0.01" min="0" :label="__('Cap (:symbol)', ['symbol' => $symbol])" wrapper="mb-0"
                                                                        :hint="__('Optional maximum fine per invoice.')" /></div>
                                </div>
                            @endif
                        @endif
                    </div>
                </fieldset>

                @if ($canEdit)
                    <div class="card-footer d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save,logo">
                            <span wire:loading.remove wire:target="save"><i class="ri-save-line me-1"></i>{{ __('Save settings') }}</span>
                            <span wire:loading wire:target="save"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Saving…') }}</span>
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </form>
</div>
