<?php

namespace App\Services\Access;

use App\Models\Role;
use App\Models\School;
use App\Support\Access\PermissionCatalog;

/**
 * Creates (or tops up) a school's built-in roles from config/access.php.
 *
 * Safe to re-run: existing roles keep any permissions the school added, and
 * only missing defaults are granted.
 */
class SyncDefaultSchoolRoles
{
    public function handle(School $school): void
    {
        tenancy()->run($school, function (): void {
            foreach (config('access.default_roles') as $name => $definition) {
                $role = Role::findOrCreate($name, 'web');

                $role->forceFill([
                    'is_system' => true,
                    'description' => $definition['description'] ?? null,
                ])->save();

                $role->givePermissionTo(PermissionCatalog::expand($definition['permissions']));
            }
        });
    }
}
