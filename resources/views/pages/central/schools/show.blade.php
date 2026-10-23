<?php

use App\Enums\SubscriptionState;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Activity;
use App\Models\School;
use App\Services\Billing\PlanLimits;
use App\Services\Schools\SchoolLifecycle;
use App\Support\Billing\PlanFeatures;
use App\Tables\ActionFailed;
use App\Tables\Central\SubscriptionPaymentsTable;

use function Livewire\Volt\{computed, layout, mount, on, protect, state, title, uses};

layout('layouts.super-admin');
title(fn () => $this->school->name);

uses([InteractsWithUi::class]);

state(['schoolId' => null])->locked();

mount(function (School $school) {
    $this->schoolId = $school->id;
});

$school = computed(fn () => School::query()->with('plan')->findOrFail($this->schoolId));

$usage = computed(fn () => app(PlanLimits::class)->usage($this->school));

$administrators = computed(fn () => $this->school->administrators()->orderBy('name')->get());

$activity = computed(fn () => Activity::query()
    ->with('causer')
    ->where('subject_type', $this->school->getMorphClass())
    ->where('subject_id', $this->school->id)
    ->latest('id')
    ->limit(8)
    ->get());

on([
    // Forms on this page refresh tables after saving: reload the cards too.
    'refresh-tables' => fn () => $this->reloadSchool(),
    // The edit form changed the address this page lives at.
    'school-updated' => function (string $slug) {
        $this->redirectRoute('central.schools.show', $slug, navigate: true);
    },
]);

$reloadSchool = protect(function () {
    unset($this->school, $this->usage, $this->administrators, $this->activity);
});

$reactivate = function () {
    $this->authorize('manage-schools');

    try {
        app(SchoolLifecycle::class)->reactivate($this->school, auth()->user());
    } catch (ActionFailed $e) {
        $this->toast($e->getMessage(), 'error');

        return;
    }

    $this->reloadSchool();
    $this->toast(__(':name has been reactivated.', ['name' => $this->school->name]));
};

$delete = function () {
    $this->authorize('manage-schools');

    try {
        app(SchoolLifecycle::class)->delete($this->school, auth()->user());
    } catch (ActionFailed $e) {
        $this->toast($e->getMessage(), 'error');

        return;
    }

    $this->flashToast(__(':name deleted.', ['name' => $this->school->name]));
    $this->redirectRoute('central.schools.index', navigate: true);
};

?>

@php
    $school = $this->school;
    $plan = $school->plan;
    $state = $school->subscriptionState();
    $settings = fn (string $key) => $school->setting($key);
@endphp

<div>
    <x-page-header :title="$school->name" :breadcrumbs="[__('Super Admin') => route('central.dashboard'), __('Schools') => route('central.schools.index'), $school->name => null]">
        <x-slot:actions>
            <a href="{{ $school->loginUrl() }}" target="_blank" rel="noopener" class="btn btn-light">
                <i class="ri-external-link-line me-1"></i>{{ __('Sign-in page') }}
            </a>
            <button type="button" class="btn btn-subtle-primary"
                    wire:click="$dispatch('open-modal', { component: 'forms.central.school', params: { school: {{ $school->id }} } })">
                <i class="ri-pencil-line me-1"></i>{{ __('Edit') }}
            </button>
            @if ($school->isActive())
                <button type="button" class="btn btn-subtle-warning"
                        wire:click="$dispatch('open-modal', { component: 'forms.central.suspend-school', params: { school: {{ $school->id }} } })">
                    <i class="ri-pause-circle-line me-1"></i>{{ __('Suspend') }}
                </button>
            @else
                <button type="button" class="btn btn-subtle-success"
                        x-on:click="SSMS.confirm({ title: @js(__('Reactivate this school?')), text: @js(__('Its users will be able to sign in again.')), confirmText: @js(__('Reactivate')), cancelText: @js(__('Cancel')) }).then(ok => ok && $wire.reactivate())">
                    <i class="ri-play-circle-line me-1"></i>{{ __('Reactivate') }}
                </button>
                <button type="button" class="btn btn-subtle-danger"
                        x-on:click="SSMS.confirm({ title: @js(__('Delete :name?', ['name' => $school->name])), text: @js(__('The school disappears from the console and its address stays reserved. Its records are kept in the database.')), confirmText: @js(__('Delete')), cancelText: @js(__('Cancel')), danger: true }).then(ok => ok && $wire.delete())">
                    <i class="ri-delete-bin-line me-1"></i>{{ __('Delete') }}
                </button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($school->isSuspended())
        <div class="alert alert-danger d-flex gap-3 align-items-start" role="alert">
            <i class="ri-forbid-line fs-4 lh-1"></i>
            <div>
                <strong>{{ $school->suspended_at ? __('Suspended on :date.', ['date' => $school->suspended_at->format('d M Y')]) : __('Suspended.') }}</strong>
                {{ __('Nobody at this school can sign in.') }}
                @if ($school->suspension_reason)
                    <div class="mt-1">{{ __('Reason: :reason', ['reason' => $school->suspension_reason]) }}</div>
                @endif
            </div>
        </div>
    @elseif ($state === SubscriptionState::Grace)
        <div class="alert alert-warning d-flex gap-3 align-items-start" role="alert">
            <i class="ri-time-line fs-4 lh-1"></i>
            <div>{{ __('The subscription ended on :ended. The school will be suspended after :grace unless a payment is recorded.', ['ended' => $school->accessEndsAt()->format('d M Y'), 'grace' => $school->graceEndsAt()->format('d M Y')]) }}</div>
        </div>
    @endif

    <div class="row g-4 mb-4">
        <div class="col-xl-4 col-md-6">
            <div class="card h-100 mb-0">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                    <h5 class="card-title mb-0">{{ __('Subscription') }}</h5>
                    <span class="badge bg-{{ $state->color() }}-subtle text-{{ $state->color() }}">{{ $state->label() }}</span>
                </div>
                <div class="card-body">
                    <dl class="detail-list mb-4">
                        <dt>{{ __('Plan') }}</dt>
                        <dd>
                            @if ($plan)
                                <span class="fw-medium">{{ $plan->name }}</span>
                                <small class="d-block text-muted">{{ $plan->formattedPrice() }} {{ $plan->billing_cycle->per() }}</small>
                            @else
                                {{ __('None') }}
                            @endif
                        </dd>
                        <dt>{{ __('Paid until') }}</dt>
                        <dd>{{ $school->subscription_ends_at?->format('d M Y') ?? '—' }}</dd>
                        <dt>{{ __('Trial until') }}</dt>
                        <dd>{{ $school->trial_ends_at?->format('d M Y') ?? '—' }}</dd>
                        @php($days = $school->daysUntilAccessEnds())
                        @if ($days !== null)
                            <dt>{{ $days >= 0 ? __('Time left') : __('Ended') }}</dt>
                            <dd @class(['text-danger' => $days < 0, 'text-warning' => $days >= 0 && $days <= config('plans.subscriptions.warning_days')])>
                                {{ $days >= 0 ? trans_choice('{0} Ends today|{1} :count day|[2,*] :count days', $days, ['count' => $days]) : trans_choice('{1} :count day ago|[2,*] :count days ago', -$days, ['count' => -$days]) }}
                            </dd>
                        @endif
                    </dl>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-primary"
                                wire:click="$dispatch('open-modal', { component: 'forms.central.subscription-payment', params: { school: {{ $school->id }} }, size: 'lg' })">
                            <i class="ri-add-line me-1"></i>{{ __('Record payment') }}
                        </button>
                        <button type="button" class="btn btn-sm btn-light"
                                wire:click="$dispatch('open-modal', { component: 'forms.central.school-subscription', params: { school: {{ $school->id }} } })">
                            <i class="ri-exchange-line me-1"></i>{{ __('Change plan or trial') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6">
            <div class="card h-100 mb-0">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('Usage & features') }}</h5></div>
                <div class="card-body">
                    <x-usage-meter class="mb-3" :label="__('Active students')" :used="$this->usage['students']['used']" :limit="$this->usage['students']['limit']" />
                    <x-usage-meter class="mb-4" :label="__('Staff')" :used="$this->usage['staff']['used']" :limit="$this->usage['staff']['limit']" />

                    <ul class="list-unstyled mb-0 vstack gap-1 fs-13">
                        @foreach (PlanFeatures::options() as $key => $label)
                            @php($included = ! $plan || $plan->includesFeature($key))
                            <li @class(['text-muted' => ! $included])>
                                <i @class(['me-2', 'ri-checkbox-circle-fill text-success' => $included, 'ri-close-circle-line' => ! $included])></i>{{ $label }}
                                <span class="visually-hidden">{{ $included ? __('included') : __('not included') }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card h-100 mb-0">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('Details') }}</h5></div>
                <div class="card-body">
                    <dl class="detail-list mb-0">
                        <dt>{{ __('Address') }}</dt><dd>/{{ $school->slug }}</dd>
                        <dt>{{ __('Email') }}</dt><dd>{{ $school->email ?: '—' }}</dd>
                        <dt>{{ __('Phone') }}</dt><dd>{{ $school->phone ?: '—' }}</dd>
                        <dt>{{ __('Location') }}</dt><dd>{{ collect([$school->address, $school->city, $school->country])->filter()->implode(', ') ?: '—' }}</dd>
                        <dt>{{ __('Currency') }}</dt><dd>{{ $settings('currency_code') }} ({{ $settings('currency_symbol') }})</dd>
                        <dt>{{ __('Timezone') }}</dt><dd>{{ $settings('timezone') }}</dd>
                        <dt>{{ __('Created') }}</dt><dd>{{ $school->created_at->format('d M Y') }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <h5 class="card-title mb-0">{{ __('School administrators') }}</h5>
                <small class="text-muted">{{ __('Accounts with full access to the school. Reset a password here if an administrator is locked out.') }}</small>
            </div>
            <button type="button" class="btn btn-sm btn-subtle-primary"
                    wire:click="$dispatch('open-modal', { component: 'forms.central.school-admin', params: { school: {{ $school->id }} } })">
                <i class="ri-user-add-line me-1"></i>{{ __('Add administrator') }}
            </button>
        </div>
        @if ($this->administrators->isEmpty())
            <x-empty-state icon="ri-user-unfollow-line" :title="__('No administrators')" :message="__('Add one so the school can be managed.')" />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle table-nowrap mb-0">
                    <thead class="bg-light bg-opacity-30">
                        <tr>
                            <th scope="col">{{ __('Name') }}</th>
                            <th scope="col">{{ __('Username') }}</th>
                            <th scope="col" class="d-none d-md-table-cell">{{ __('Email / phone') }}</th>
                            <th scope="col" class="d-none d-md-table-cell">{{ __('Last sign-in') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col" class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->administrators as $admin)
                            <tr wire:key="admin-{{ $admin->id }}">
                                <td class="fw-medium">
                                    {{ $admin->name }}
                                    @if ($admin->must_change_password)
                                        <span class="badge bg-warning-subtle text-warning ms-1">{{ __('Temporary password') }}</span>
                                    @endif
                                </td>
                                <td>{{ $admin->username ?? '—' }}</td>
                                <td class="d-none d-md-table-cell">{{ collect([$admin->email, $admin->phone])->filter()->implode(' · ') ?: '—' }}</td>
                                <td class="d-none d-md-table-cell text-muted">{{ $admin->last_login_at?->diffForHumans() ?? __('Never') }}</td>
                                <td><span class="badge bg-{{ $admin->status->color() }}-subtle text-{{ $admin->status->color() }}">{{ $admin->status->label() }}</span></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-subtle-warning icon-btn-sm" title="{{ __('Reset password') }}" aria-label="{{ __('Reset password for :name', ['name' => $admin->name]) }}"
                                            wire:click="$dispatch('open-modal', { component: 'forms.central.reset-password', params: { user: {{ $admin->id }} } })">
                                        <i class="ri-lock-password-line"></i>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <h5 class="mb-3">{{ __('Subscription payments') }}</h5>
            <livewire:components.data-table :table="SubscriptionPaymentsTable::class" :params="['school_id' => $school->id]" />
        </div>

        <div class="col-xl-4">
            <h5 class="mb-3">{{ __('Recent activity') }}</h5>
            <div class="card mb-0">
                @if ($this->activity->isEmpty())
                    <x-empty-state icon="ri-history-line" :title="__('Nothing yet')" />
                @else
                    <ul class="list-group list-group-flush">
                        @foreach ($this->activity as $entry)
                            <li class="list-group-item" wire:key="activity-{{ $entry->id }}">
                                <div class="fw-medium">{{ __($entry->description) }}</div>
                                <small class="text-muted">
                                    {{ $entry->created_at->diffForHumans() }}
                                    @if ($entry->causer) · {{ $entry->causer->name }} @endif
                                    @if ($reason = $entry->properties['reason'] ?? null) · {{ $reason }} @endif
                                </small>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
