{{--
    Tells a school's administrators that its trial or subscription is about
    to end (or has ended and is in its grace period). Renders nothing otherwise.
--}}
@props(['school'])

@use('App\Enums\SubscriptionState')

@php
    $state = $school->subscriptionState();
    $days = $school->daysUntilAccessEnds();
    $warningDays = (int) config('plans.subscriptions.warning_days');

    $show = $state === SubscriptionState::Grace
        || (in_array($state, [SubscriptionState::Trial, SubscriptionState::Active], true) && $days !== null && $days <= $warningDays);
@endphp

@if ($show && auth()->user()?->can('settings.view'))
    <div @class(['alert d-flex align-items-start gap-3 mb-4', 'alert-danger' => $state === SubscriptionState::Grace, 'alert-warning' => $state !== SubscriptionState::Grace]) role="status">
        <i class="{{ $state === SubscriptionState::Grace ? 'ri-error-warning-line' : 'ri-time-line' }} fs-4 lh-1"></i>
        <div>
            @if ($state === SubscriptionState::Grace)
                <strong>{{ __('Your subscription ended on :date.', ['date' => $school->accessEndsAt()->format($school->setting('date_format'))]) }}</strong>
                {{ __('The school will be suspended on :date unless it is renewed. Please contact the platform administrator.', ['date' => $school->graceEndsAt()->addDay()->format($school->setting('date_format'))]) }}
            @elseif ($state === SubscriptionState::Trial)
                <strong>{{ trans_choice('{0} Your free trial ends today.|{1} Your free trial ends tomorrow.|[2,*] Your free trial ends in :count days.', $days, ['count' => $days]) }}</strong>
                {{ __('Contact the platform administrator to choose a plan and keep using the system.') }}
            @else
                <strong>{{ trans_choice('{0} Your subscription ends today.|{1} Your subscription ends tomorrow.|[2,*] Your subscription ends in :count days.', $days, ['count' => $days]) }}</strong>
                {{ __('Contact the platform administrator to renew it.') }}
            @endif
        </div>
    </div>
@endif
