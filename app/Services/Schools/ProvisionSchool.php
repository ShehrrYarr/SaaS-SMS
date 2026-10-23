<?php

namespace App\Services\Schools;

use App\Enums\SchoolStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\School;
use App\Models\User;
use App\Services\Access\SyncDefaultSchoolRoles;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Creates a ready-to-use school in one transaction:
 *
 *  1. the school record (plan, trial, locale settings);
 *  2. its built-in roles (config/access.php);
 *  3. its starting records (SeedSchoolDefaults: Main Campus, leave types);
 *  4. the first School Admin account, which must change its password at
 *     first sign-in;
 *  5. optionally, the first subscription payment.
 *
 * Later phases add their per-school defaults (grade scales, finance
 * categories) to SeedSchoolDefaults.
 */
class ProvisionSchool
{
    public function __construct(
        private readonly SyncDefaultSchoolRoles $syncRoles,
        private readonly SubscriptionManager $subscriptions,
        private readonly SeedSchoolDefaults $defaults,
    ) {}

    /**
     * @param  array{name: string, slug: string, email?: string|null, phone?: string|null, address?: string|null, city?: string|null, country?: string|null, plan_id?: int|null, trial_ends_at?: string|null, settings?: array<string, mixed>}  $school
     * @param  array{name: string, username: string, email?: string|null, phone?: string|null}  $admin
     * @param  array<string, mixed>|null  $payment  see SubscriptionManager::recordPayment()
     */
    public function handle(array $school, array $admin, #[SensitiveParameter] string $password, ?array $payment = null, ?User $by = null): ProvisionedSchool
    {
        return DB::transaction(function () use ($school, $admin, $password, $payment, $by) {
            $record = School::query()->create([
                ...$school,
                'status' => SchoolStatus::Active,
            ]);

            $this->syncRoles->handle($record);

            $user = tenancy()->run($record, function (School $record) use ($admin, $password) {
                $this->defaults->handle($record);

                $user = User::query()->create([
                    ...$admin,
                    'password' => $password,
                    'user_type' => UserType::SchoolAdmin,
                    'status' => UserStatus::Active,
                    'must_change_password' => true,
                ]);

                $user->assignRole(UserType::SchoolAdmin->defaultRole());

                return $user;
            });

            if ($payment !== null) {
                $this->subscriptions->recordPayment($record, $payment, $by);
            }

            activity('schools')->performedOn($record)->causedBy($by)
                ->withProperties(['plan_id' => $record->plan_id, 'admin' => $user->username])
                ->log('School provisioned');

            return new ProvisionedSchool($record->refresh(), $user, $password);
        });
    }
}
