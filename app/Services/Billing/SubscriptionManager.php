<?php

namespace App\Services\Billing;

use App\Enums\BillingCycle;
use App\Enums\PaymentMethod;
use App\Models\Plan;
use App\Models\School;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Schools\SchoolLifecycle;
use App\Support\Billing\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Offline SaaS billing: the Super Admin records payments by hand, and a
 * school is paid up to the latest period_end of its recorded payments.
 */
class SubscriptionManager
{
    public function __construct(private readonly SchoolLifecycle $lifecycle) {}

    /**
     * The period a new payment should cover: from the day after the paid-up
     * date (or today, if that has passed) for one billing cycle of the plan.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function suggestPeriod(School $school, ?Plan $plan = null): array
    {
        $plan ??= $school->plan;
        $start = today();

        if ($school->subscription_ends_at?->gte(today())) {
            $start = $school->subscription_ends_at->copy()->addDay();
        } elseif ($school->trial_ends_at?->gte(today())) {
            $start = $school->trial_ends_at->copy()->addDay();
        }

        return [$start, $this->periodEnd($start, $plan?->billing_cycle ?? BillingCycle::Monthly)];
    }

    /**
     * Last day of a billing period starting on $start.
     */
    public function periodEnd(Carbon $start, BillingCycle $cycle): Carbon
    {
        return $start->copy()->addMonthsNoOverflow($cycle->months())->subDay();
    }

    /**
     * @param  array{plan_id: int|null, amount: numeric-string|float, period_start: string, period_end: string, paid_on: string, payment_method: string|PaymentMethod, reference?: string|null, notes?: string|null}  $data
     */
    public function recordPayment(School $school, array $data, ?User $recorder = null, bool $reactivate = false): SubscriptionPayment
    {
        return DB::transaction(function () use ($school, $data, $recorder, $reactivate) {
            $payment = tenancy()->run($school, fn () => SubscriptionPayment::query()->create([
                ...$data,
                'currency' => Money::currency(),
                'recorded_by' => $recorder?->getKey(),
            ]));

            $this->syncPaidUntil($school);

            activity('billing')
                ->performedOn($school)
                ->causedBy($recorder)
                ->withProperties(['amount' => $payment->amount, 'period_end' => $payment->period_end->toDateString()])
                ->log('Subscription payment recorded');

            if ($reactivate && $school->isSuspended()) {
                $this->lifecycle->reactivate($school, $recorder);
            }

            return $payment;
        });
    }

    public function deletePayment(SubscriptionPayment $payment, ?User $by = null): void
    {
        DB::transaction(function () use ($payment, $by) {
            $school = School::query()->findOrFail($payment->school_id);

            $payment->delete();
            $this->syncPaidUntil($school);

            activity('billing')
                ->performedOn($school)
                ->causedBy($by)
                ->withProperties(['amount' => $payment->amount, 'period_end' => $payment->period_end->toDateString()])
                ->log('Subscription payment deleted');
        });
    }

    /**
     * Recompute the school's paid-up date from its remaining payments.
     */
    public function syncPaidUntil(School $school): void
    {
        $latest = tenancy()->run($school, fn () => SubscriptionPayment::query()->max('period_end'));

        $school->forceFill(['subscription_ends_at' => $latest])->save();
    }
}
