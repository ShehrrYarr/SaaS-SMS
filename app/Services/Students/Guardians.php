<?php

namespace App\Services\Students;

use App\Enums\GuardianRelation;
use App\Enums\UserType;
use App\Models\ParentProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\People\Accounts;
use App\Services\People\Credentials;
use App\Tables\ActionFailed;

/**
 * Parents and guardians, and their links to students.
 *
 * One person has one account per school. A guardian whose phone or email
 * already belongs to an account is linked through that account: an existing
 * parent gets the new child added, and a staff member who is also a parent
 * gets a parent profile and the Parent role on their staff account (the
 * portal switcher then shows both portals). Only a brand-new person gets a
 * new account, and so new sign-in details.
 */
class Guardians
{
    public function __construct(private readonly Accounts $accounts) {}

    /**
     * Find or create the parent described by $data.
     *
     * @param  array{name: string, phone?: string|null, email?: string|null, occupation?: string|null, address?: string|null, username?: string|null}  $data
     * @return array{0: ParentProfile, 1: Credentials|null} credentials only for a new account
     *
     * @throws ActionFailed when the phone or email belongs to a student
     */
    public function findOrCreate(array $data): array
    {
        $existing = $this->accountMatching($data['phone'] ?? null, $data['email'] ?? null);

        if ($existing) {
            if ($existing->trashed()) {
                throw new ActionFailed(__(':value belongs to a deleted account. Use another, or ask an administrator to restore that account.', [
                    'value' => $data['phone'] ?? $data['email'],
                ]));
            }

            if ($existing->user_type === UserType::Student) {
                throw new ActionFailed(__(':value belongs to a student account, so it can\'t be used for a parent.', [
                    'value' => $data['phone'] ?? $data['email'],
                ]));
            }

            return [$this->profileFor($existing, $data), null];
        }

        $account = $this->accounts->create(UserType::Parent, [
            'name' => $data['name'],
            'username' => $data['username'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
        ], UserType::Parent->defaultRole());

        $profile = ParentProfile::query()->create([
            'user_id' => $account->user->id,
            'occupation' => $data['occupation'] ?? null,
            'address' => $data['address'] ?? null,
        ]);

        return [$profile, $account->credentials];
    }

    /**
     * Link a parent to a student (or update the link), optionally as the
     * primary contact.
     */
    public function link(StudentProfile $student, ParentProfile $parent, GuardianRelation|string $relation, bool $primary = false): void
    {
        $relation = $relation instanceof GuardianRelation ? $relation : GuardianRelation::from($relation);

        // A student's first guardian is always the primary contact.
        $primary = $primary || $student->parents()->wherePivot('parent_profile_id', '!=', $parent->id)->doesntExist();

        if ($student->parents()->whereKey($parent->id)->exists()) {
            $student->parents()->updateExistingPivot($parent->id, ['relation' => $relation->value]);
        } else {
            $student->parents()->attach($parent->id, ['relation' => $relation->value, 'is_primary' => false]);
        }

        if ($primary) {
            $this->makePrimary($student, $parent);
        }
    }

    public function unlink(StudentProfile $student, ParentProfile $parent): void
    {
        $wasPrimary = (bool) $student->parents()->whereKey($parent->id)->first()?->pivot->is_primary;

        $student->parents()->detach($parent->id);

        // Someone must stay the primary contact.
        if ($wasPrimary && ($next = $student->parents()->orderBy('parent_student.id')->first())) {
            $this->makePrimary($student, $next);
        }
    }

    public function makePrimary(StudentProfile $student, ParentProfile $parent): void
    {
        foreach ($student->parents()->get() as $linked) {
            $student->parents()->updateExistingPivot($linked->id, ['is_primary' => $linked->is($parent)]);
        }
    }

    /**
     * The account in this school with this phone or email, if any.
     */
    public function accountMatching(?string $phone, ?string $email): ?User
    {
        $phone = trim((string) $phone);
        $email = trim((string) $email);

        if ($phone === '' && $email === '') {
            return null;
        }

        return User::query()->withTrashed()
            ->where(fn ($query) => $query
                ->when($phone !== '', fn ($query) => $query->orWhere('phone', $phone))
                ->when($email !== '', fn ($query) => $query->orWhere('email', $email)))
            ->with('parentProfile')
            ->first();
    }

    /**
     * The parent profile of an existing account, created (with the Parent
     * role) if the account doesn't have one yet.
     *
     * @param  array{occupation?: string|null, address?: string|null}  $data
     */
    private function profileFor(User $user, array $data): ParentProfile
    {
        if ($user->parentProfile) {
            return $user->parentProfile;
        }

        // A parent profile removed earlier comes back rather than being duplicated.
        $profile = ParentProfile::query()->withTrashed()->where('user_id', $user->id)->first();

        if ($profile) {
            $profile->restore();
        } else {
            $profile = ParentProfile::query()->create([
                'user_id' => $user->id,
                'occupation' => $data['occupation'] ?? null,
                'address' => $data['address'] ?? null,
            ]);
        }

        if (! $user->hasRole(UserType::Parent->defaultRole())) {
            $user->assignRole(UserType::Parent->defaultRole());
        }

        return $profile;
    }
}
