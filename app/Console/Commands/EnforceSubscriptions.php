<?php

namespace App\Console\Commands;

use App\Enums\SchoolStatus;
use App\Enums\SubscriptionState;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\School;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Schools\SchoolLifecycle;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Suspends active schools whose trial or paid period ended more than the
 * grace period ago, and tells the super admins which ones. Scheduled daily
 * (routes/console.php).
 */
#[AsCommand(name: 'schools:enforce-subscriptions')]
class EnforceSubscriptions extends Command
{
    protected $signature = 'schools:enforce-subscriptions {--dry-run : List the schools without suspending them}';

    protected $description = 'Suspend schools whose subscription has run out';

    public function handle(SchoolLifecycle $lifecycle): int
    {
        if (! config('plans.subscriptions.auto_suspend')) {
            $this->info('Automatic suspension is turned off (SUBSCRIPTION_AUTO_SUSPEND).');

            return self::SUCCESS;
        }

        return tenancy()->runCentral(function () use ($lifecycle) {
            $expired = School::query()
                ->where('status', SchoolStatus::Active)
                ->whereSubscriptionState(SubscriptionState::Expired)
                ->orderBy('name')
                ->get();

            if ($expired->isEmpty()) {
                $this->info('No expired subscriptions.');

                return self::SUCCESS;
            }

            foreach ($expired as $school) {
                $this->line("{$school->name} (/{$school->slug}): ended {$school->accessEndsAt()->toDateString()}");

                if (! $this->option('dry-run')) {
                    $lifecycle->suspend($school, __('Subscription ended on :date.', ['date' => $school->accessEndsAt()->format('d M Y')]));
                }
            }

            if ($this->option('dry-run')) {
                return self::SUCCESS;
            }

            $superAdmins = User::query()
                ->whereNull('school_id')
                ->where('user_type', UserType::SuperAdmin)
                ->where('status', UserStatus::Active)
                ->get();

            Notification::send($superAdmins, new SystemNotification(
                title: trans_choice('{1} :count school suspended for non-payment|[2,*] :count schools suspended for non-payment', $expired->count(), ['count' => $expired->count()]),
                message: $expired->pluck('name')->take(5)->implode(', ').($expired->count() > 5 ? '…' : ''),
                url: route('central.schools.index'),
                icon: 'ri-forbid-line',
                color: 'danger',
            ));

            $this->info("Suspended {$expired->count()} school(s).");

            return self::SUCCESS;
        });
    }
}
