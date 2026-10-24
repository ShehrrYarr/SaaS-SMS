<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Needs the scheduler on the server: * * * * * php artisan schedule:run
Schedule::command('schools:enforce-subscriptions')->dailyAt('00:30')->withoutOverlapping()->onOneServer();
Schedule::command('notices:announce')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('fees:apply-late-fees')->dailyAt('01:00')->withoutOverlapping()->onOneServer();
Schedule::command('library:send-reminders')->dailyAt('07:00')->withoutOverlapping()->onOneServer();
