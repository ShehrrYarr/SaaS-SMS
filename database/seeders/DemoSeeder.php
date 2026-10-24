<?php

namespace Database\Seeders;

use Database\Seeders\Demo\DemoSchools;
use Illuminate\Database\Seeder;

/**
 * The demo schools (see Demo\GreenfieldAcademy): /greenfield/login and the
 * smaller schools, every account signing in with the password "password".
 * Schools already there are kept; `php artisan demo:seed --fresh` rebuilds them.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        (new DemoSchools(report: fn (string $line) => $this->command?->info($line)))->build();

        $this->command?->info('Demo: /greenfield/login as admin (or any demo account) with the password "'.DemoSchools::PASSWORD.'".');
    }
}
