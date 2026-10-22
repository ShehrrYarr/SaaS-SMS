<?php

namespace Database\Seeders;

use App\Support\Access\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Syncs config/access.php into the permissions table. Safe to run on every
 * deploy: it only adds missing permissions, and lists ones no longer in the
 * catalog so they can be removed deliberately.
 */
class PermissionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = PermissionCatalog::all();

        // One bulk insert: Permission::findOrCreate() would rebuild Spatie's
        // cache after every single row.
        $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
        $now = now();

        Permission::query()->insert(array_map(
            fn (string $name) => ['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now],
            array_values(array_diff($catalog, $existing)),
        ));

        $stale = Permission::query()->whereNotIn('name', $catalog)->pluck('name');

        if ($stale->isNotEmpty()) {
            $this->command?->warn('Permissions not in config/access.php: '.$stale->implode(', '));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(count($catalog).' permissions in catalog.');
    }
}
