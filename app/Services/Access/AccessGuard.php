<?php

namespace App\Services\Access;

use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionCatalog;
use Illuminate\Support\Collection;

/**
 * Stops privilege escalation inside a school: nobody can hand out access
 * they don't hold themselves.
 *
 *  - a permission can be granted to a role only by someone who holds it;
 *  - a role can be given or taken away only by someone who holds all of its
 *    permissions;
 *  - an account can be managed (roles, status, password) only by someone who
 *    holds everything that account holds.
 *
 * Holdings are compared through roles, before the plan filter, so a missing
 * plan feature never stops an administrator managing an account. Entry to
 * the student and parent portals is left out of the comparison: those
 * portals only show a person their own records, so they give no power over
 * anyone else (and "*" deliberately doesn't include them).
 */
class AccessGuard
{
    /** @var array<int, Collection<int, string>> */
    private array $held = [];

    /**
     * Permission names the user holds in the active school.
     *
     * @return Collection<int, string>
     */
    public function permissionsOf(User $user): Collection
    {
        return $this->held[$user->getKey()] ??= $user->loadMissing(['roles.permissions', 'permissions'])->getAllPermissions()->pluck('name');
    }

    public function canGrant(User $editor, string $permission): bool
    {
        return $this->permissionsOf($editor)->contains($permission);
    }

    public function canAssign(User $editor, Role $role): bool
    {
        return $role->permissions->pluck('name')
            ->diff($this->permissionsOf($editor))
            ->diff(PermissionCatalog::selfServicePortalPermissions())
            ->isEmpty();
    }

    public function canManage(User $editor, User $account): bool
    {
        if ($editor->is($account) || $account->isSuperAdmin()) {
            return false;
        }

        return $this->permissionsOf($account)
            ->diff($this->permissionsOf($editor))
            ->diff(PermissionCatalog::selfServicePortalPermissions())
            ->isEmpty();
    }

    public function forget(?User $user = null): void
    {
        if ($user) {
            unset($this->held[$user->getKey()]);
        } else {
            $this->held = [];
        }
    }
}
