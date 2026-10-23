<?php

namespace App\Services\Students;

use App\Enums\UserType;
use App\Models\ParentProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\People\Credentials;
use App\Tables\ActionFailed;
use Illuminate\Support\Facades\DB;

/**
 * Parents managed from the Parents screen: details and the children they
 * are linked to. (Admissions add parents through Guardians directly.)
 */
class ParentRecords
{
    public function __construct(private readonly Guardians $guardians) {}

    /**
     * @param  array{name: string, username?: string|null, phone?: string|null, email?: string|null, occupation?: string|null, address?: string|null}  $data
     * @param  array<int, array{relation: string, is_primary?: bool}>  $children  student id => link
     * @return array{0: ParentProfile, 1: Credentials|null}
     *
     * @throws ActionFailed
     */
    public function create(array $data, array $children = [], ?User $by = null): array
    {
        return DB::transaction(function () use ($data, $children, $by) {
            [$parent, $credentials] = $this->guardians->findOrCreate($data);

            $this->syncChildren($parent, $children);

            activity('parent_profile')->performedOn($parent)->causedBy($by)
                ->withProperties(['children' => count($children), 'existing_account' => $credentials === null])
                ->log('Parent added');

            return [$parent, $credentials];
        });
    }

    /**
     * @param  array{name: string, username: string, phone?: string|null, email?: string|null, occupation?: string|null, address?: string|null}  $data
     * @param  array<int, array{relation: string, is_primary?: bool}>  $children
     */
    public function update(ParentProfile $parent, array $data, array $children): void
    {
        DB::transaction(function () use ($parent, $data, $children) {
            $blank = fn ($value) => blank($value) ? null : trim((string) $value);

            $parent->user->update([
                'name' => trim($data['name']),
                'username' => trim($data['username']),
                'phone' => $blank($data['phone'] ?? null),
                'email' => $blank($data['email'] ?? null),
            ]);

            $parent->update([
                'occupation' => $blank($data['occupation'] ?? null),
                'address' => $blank($data['address'] ?? null),
            ]);

            $this->syncChildren($parent, $children);
        });
    }

    /**
     * Unlink the parent from their children and remove the parent profile.
     * A parent-only account goes with it; a staff member who is also a
     * parent keeps their account and just loses the Parent role.
     */
    public function delete(ParentProfile $parent, ?User $by = null): void
    {
        DB::transaction(function () use ($parent, $by) {
            foreach ($parent->children()->get() as $child) {
                $this->guardians->unlink($child, $parent);
            }

            activity('parent_profile')->performedOn($parent)->causedBy($by)
                ->withProperties(['name' => $parent->user?->name])
                ->log('Parent deleted');

            $user = $parent->user;
            $parent->delete();

            if ($user?->user_type === UserType::Parent) {
                $user->delete();
            } elseif ($user) {
                $user->removeRole(UserType::Parent->defaultRole());
            }
        });
    }

    /**
     * Make the parent's children exactly $children, keeping each child's
     * other guardians.
     *
     * @param  array<int, array{relation: string, is_primary?: bool}>  $children
     */
    public function syncChildren(ParentProfile $parent, array $children): void
    {
        $current = $parent->children()->pluck('student_profiles.id')->all();

        foreach (array_diff($current, array_keys($children)) as $removed) {
            $this->guardians->unlink(StudentProfile::query()->findOrFail($removed), $parent);
        }

        foreach ($children as $studentId => $link) {
            $this->guardians->link(StudentProfile::query()->findOrFail($studentId), $parent, $link['relation'], (bool) ($link['is_primary'] ?? false));
        }
    }
}
