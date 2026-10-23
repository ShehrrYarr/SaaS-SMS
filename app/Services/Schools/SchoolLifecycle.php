<?php

namespace App\Services\Schools;

use App\Enums\SchoolStatus;
use App\Enums\SubscriptionState;
use App\Models\School;
use App\Models\User;
use App\Tables\ActionFailed;

/**
 * Suspending and reactivating schools. A suspended school's portals answer
 * 403 until it is reactivated; its data is untouched.
 */
class SchoolLifecycle
{
    public function suspend(School $school, string $reason, ?User $by = null): void
    {
        $school->forceFill([
            'status' => SchoolStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ])->save();

        activity('schools')->performedOn($school)->causedBy($by)
            ->withProperties(['reason' => $reason])
            ->log('School suspended');
    }

    /**
     * @throws ActionFailed when the subscription has run out, since the
     *                      nightly check would only suspend the school again
     */
    public function reactivate(School $school, ?User $by = null): void
    {
        if (config('plans.subscriptions.auto_suspend') && $school->subscriptionState() === SubscriptionState::Expired) {
            throw new ActionFailed(__(':school\'s subscription ended on :date. Record a payment or extend the trial to reactivate it.', [
                'school' => $school->name,
                'date' => $school->accessEndsAt()->format('d M Y'),
            ]));
        }

        $school->forceFill([
            'status' => SchoolStatus::Active,
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        activity('schools')->performedOn($school)->causedBy($by)->log('School reactivated');
    }

    /**
     * Soft-delete a school. Only suspended schools can be deleted, so a school
     * is always taken offline (and checked) first. Its slug stays reserved.
     *
     * @throws ActionFailed
     */
    public function delete(School $school, ?User $by = null): void
    {
        if (! $school->isSuspended()) {
            throw new ActionFailed(__('Suspend :school before deleting it.', ['school' => $school->name]));
        }

        $school->delete();

        activity('schools')->performedOn($school)->causedBy($by)->log('School deleted');
    }
}
