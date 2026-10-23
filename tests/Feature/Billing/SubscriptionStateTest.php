<?php

use App\Enums\PaymentMethod;
use App\Enums\SchoolStatus;
use App\Enums\SubscriptionState;
use App\Enums\UserType;
use App\Models\Plan;
use App\Models\School;
use App\Models\SubscriptionPayment;
use App\Notifications\SystemNotification;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['plans.subscriptions.grace_days' => 7, 'plans.subscriptions.auto_suspend' => true]);
});

/**
 * Days relative to today, null for none.
 */
function schoolEnding(?int $trialDays, ?int $paidDays, array $attributes = []): School
{
    return makeSchool([
        'trial_ends_at' => $trialDays === null ? null : today()->addDays($trialDays),
        'subscription_ends_at' => $paidDays === null ? null : today()->addDays($paidDays),
        ...$attributes,
    ]);
}

it('works out where a school stands', function (?int $trial, ?int $paid, SubscriptionState $expected) {
    $school = schoolEnding($trial, $paid);

    expect($school->subscriptionState())->toBe($expected)
        // The SQL version agrees with the PHP version.
        ->and(School::query()->whereSubscriptionState($expected)->pluck('id')->all())->toBe([$school->id]);
})->with([
    'no end date' => [null, null, SubscriptionState::Open],
    'paid' => [null, 30, SubscriptionState::Active],
    'paid through today' => [null, 0, SubscriptionState::Active],
    'paid, with an old trial' => [-60, 30, SubscriptionState::Active],
    'on trial' => [10, null, SubscriptionState::Trial],
    'trial outlasting an old payment' => [10, -40, SubscriptionState::Trial],
    'trial ended yesterday' => [-1, null, SubscriptionState::Grace],
    'last day of grace' => [null, -7, SubscriptionState::Grace],
    'past grace' => [null, -8, SubscriptionState::Expired],
    'trial long gone' => [-30, null, SubscriptionState::Expired],
]);

it('finds schools whose access ends soon', function () {
    $soon = schoolEnding(null, 10);
    schoolEnding(null, 40);
    schoolEnding(null, -1);
    schoolEnding(null, null);

    expect(School::query()->expiringWithin(30)->pluck('id')->all())->toBe([$soon->id]);
});

it('suggests the period after the paid-up date', function () {
    $plan = Plan::factory()->create(['billing_cycle' => 'quarterly']);
    $school = makeSchool(['plan_id' => $plan->id, 'subscription_ends_at' => '2030-01-31']);

    [$start, $end] = app(SubscriptionManager::class)->suggestPeriod($school);

    expect($start->toDateString())->toBe('2030-02-01')
        ->and($end->toDateString())->toBe('2030-04-30');
});

it('starts a lapsed school\'s next period today', function () {
    $school = makeSchool(['plan_id' => Plan::factory()->create()->id, 'subscription_ends_at' => today()->subMonths(3)]);

    [$start] = app(SubscriptionManager::class)->suggestPeriod($school);

    expect($start->toDateString())->toBe(today()->toDateString());
});

function recordPayment(School $school, string $start, string $end, bool $reactivate = false): SubscriptionPayment
{
    return app(SubscriptionManager::class)->recordPayment($school, [
        'plan_id' => $school->plan_id,
        'amount' => 100,
        'period_start' => $start,
        'period_end' => $end,
        'paid_on' => today()->toDateString(),
        'payment_method' => PaymentMethod::Cash,
    ], reactivate: $reactivate);
}

it('keeps the paid-until date in step with the payments', function () {
    $school = makeSchool();

    $first = recordPayment($school, '2031-01-01', '2031-01-31');
    $second = recordPayment($school, '2031-02-01', '2031-02-28');

    expect($school->fresh()->subscription_ends_at->toDateString())->toBe('2031-02-28')
        ->and($first->school_id)->toBe($school->id)
        ->and($first->currency)->toBe('USD');

    app(SubscriptionManager::class)->deletePayment($second);
    expect($school->fresh()->subscription_ends_at->toDateString())->toBe('2031-01-31');

    app(SubscriptionManager::class)->deletePayment($first);
    expect($school->fresh()->subscription_ends_at)->toBeNull();
});

it('reactivates a suspended school when asked', function () {
    $school = makeSchool(['status' => SchoolStatus::Suspended, 'suspended_at' => now(), 'suspension_reason' => 'Unpaid']);

    recordPayment($school, today()->toDateString(), today()->addMonth()->toDateString(), reactivate: true);

    expect($school->fresh())
        ->status->toBe(SchoolStatus::Active)
        ->suspension_reason->toBeNull();
});

it('suspends schools past their grace period and tells the super admins', function () {
    Notification::fake();
    $superAdmin = makeSuperAdmin();

    $expired = schoolEnding(null, -8, ['name' => 'Lapsed Academy']);
    $grace = schoolEnding(null, -3);
    $paid = schoolEnding(null, 20);
    $open = schoolEnding(null, null);

    $this->artisan('schools:enforce-subscriptions')->assertSuccessful();

    expect($expired->fresh()->status)->toBe(SchoolStatus::Suspended)
        ->and($expired->fresh()->suspension_reason)->toContain('Subscription ended on')
        ->and($grace->fresh()->status)->toBe(SchoolStatus::Active)
        ->and($paid->fresh()->status)->toBe(SchoolStatus::Active)
        ->and($open->fresh()->status)->toBe(SchoolStatus::Active);

    Notification::assertSentTo($superAdmin, SystemNotification::class, fn ($notification) => str_contains($notification->message, 'Lapsed Academy'));
});

it('only lists schools in a dry run', function () {
    $expired = schoolEnding(null, -30);

    $this->artisan('schools:enforce-subscriptions --dry-run')->assertSuccessful();

    expect($expired->fresh()->status)->toBe(SchoolStatus::Active);
});

it('can be switched off', function () {
    config(['plans.subscriptions.auto_suspend' => false]);
    $expired = schoolEnding(null, -30);

    $this->artisan('schools:enforce-subscriptions')->assertSuccessful();

    expect($expired->fresh()->status)->toBe(SchoolStatus::Active);
});

it('warns school admins before the subscription ends', function () {
    $school = schoolEnding(null, 5, ['slug' => 'alpha']);
    seedSchoolRoles($school);

    $this->actingAs(makeUserWithRole($school, UserType::SchoolAdmin, 'School Admin'))
        ->get('/alpha/admin')
        ->assertSee('Your subscription ends in 5 days.');

    $this->actingAs(makeUserWithRole($school, UserType::Teacher, 'Teacher'))
        ->get('/alpha/teacher')
        ->assertDontSee('Your subscription ends');
});

it('stays quiet while the subscription has a while to run', function () {
    $school = schoolEnding(null, 60, ['slug' => 'alpha']);
    seedSchoolRoles($school);

    $this->actingAs(makeUserWithRole($school, UserType::SchoolAdmin, 'School Admin'))
        ->get('/alpha/admin')
        ->assertDontSee('Your subscription ends');
});
