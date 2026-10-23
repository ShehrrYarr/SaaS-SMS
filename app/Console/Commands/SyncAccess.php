<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Access\SyncDefaultSchoolRoles;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

/**
 * Run on deploy: adds permissions new to config/access.php and tops up every
 * school's built-in roles with the defaults they are missing. Permissions a
 * school added to a role are kept.
 */
#[AsCommand(name: 'access:sync')]
class SyncAccess extends Command
{
    protected $signature = 'access:sync';

    protected $description = 'Sync the permission catalog and every school\'s built-in roles';

    public function handle(SyncDefaultSchoolRoles $roles): int
    {
        $this->call('db:seed', ['--class' => PermissionCatalogSeeder::class, '--force' => true]);

        $count = 0;

        School::query()->each(function (School $school) use ($roles, &$count) {
            $roles->handle($school);
            $count++;
        });

        $this->info("Built-in roles synced for {$count} school(s).");

        return self::SUCCESS;
    }
}
