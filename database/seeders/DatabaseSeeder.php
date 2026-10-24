<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PermissionCatalogSeeder::class,
            PlanSeeder::class,
            SuperAdminSeeder::class,
        ]);

        // On a server, build the demo with `php artisan demo:seed` instead.
        if (app()->isLocal()) {
            $this->call(DemoSeeder::class);
        }
    }
}
