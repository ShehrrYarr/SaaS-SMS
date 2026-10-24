<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Library\Library;
use App\Support\Billing\PlanFeatures;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

/**
 * Daily: reminds borrowers (and a student's parents) the day before a book
 * is due, and once when it becomes overdue. Each school runs on its own
 * timezone's "today"; schools whose plan has no library are skipped.
 */
#[AsCommand(name: 'library:send-reminders')]
class SendLibraryReminders extends Command
{
    protected $signature = 'library:send-reminders';

    protected $description = 'Remind borrowers of library books due tomorrow or overdue';

    public function handle(Library $library): int
    {
        $sent = 0;

        School::query()->with('plan')->each(function (School $school) use ($library, &$sent) {
            if (! $school->isActive() || ! PlanFeatures::allows($school->plan, 'library.view')) {
                return;
            }

            $sent += tenancy()->run($school, fn () => $library->sendReminders($school->today()));
        });

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
