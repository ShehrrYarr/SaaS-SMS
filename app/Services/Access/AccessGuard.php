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
 *
 * HR is the exception that keeps staff records workable: with staff.edit it
 * can keep the record of anyone but itself and the school's administrators,
 * and hand out the built-in job roles when placing staff. It still can't
 * reset their passwords, which is what would let it act as them.
 */
class AccessGuard
{
    /** Built-in roles someone keeping staff records may give, whatever they hold. */
    private const JOB_ROLES = ['Teacher', 'Accountant', 'Librarian', 'Transport Manager', 'Hostel Warden'];

    /** Holding any of these makes an account an administrator, off limits to staff.edit alone. */
    private const ADMINISTRATOR_PERMISSIONS = ['roles.edit', 'users.edit', 'users.reset-password', 'settings.edit'];

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

    /**
     * Whether the editor may keep this account's staff record: details,
     * employment, status and job roles (not its password).
     */
    public function canEditStaffRecord(User $editor, User $account): bool
    {
        if ($this->canManage($editor, $account)) {
            return true;
        }

        return ! $editor->is($account)
            && ! $account->isSuperAdmin()
            && $this->permissionsOf($editor)->contains('staff.edit')
            && $this->permissionsOf($account)->intersect(self::ADMINISTRATOR_PERMISSIONS)->isEmpty();
    }

    /**
     * Whether the editor may give or take this role on the staff form.
     */
    public function canAssignOnStaffRecord(User $editor, Role $role): bool
    {
        if ($this->canAssign($editor, $role)) {
            return true;
        }

        return $role->is_system
            && in_array($role->name, self::JOB_ROLES, true)
            && $this->permissionsOf($editor)->intersect(['staff.create', 'staff.edit'])->isNotEmpty();
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
