<?php

namespace App\Console\Commands;

use App\Jobs\SendNoticeNotifications;
use App\Models\Notice;
use App\Models\School;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

/**
 * Announces notices whose scheduled publish time has come. Notices published
 * straight away are announced when they are published; this catches the
 * scheduled ones. Runs every few minutes (routes/console.php).
 */
#[AsCommand(name: 'notices:announce')]
class AnnounceScheduledNotices extends Command
{
    protected $signature = 'notices:announce';

    protected $description = 'Send in-app notifications for notices that have just gone live';

    public function handle(): int
    {
        $due = tenancy()->runCentral(fn () => Notice::query()
            ->live()
            ->whereNull('notified_at')
            ->get(['id', 'school_id']));

        $schools = School::query()->whereIn('id', $due->pluck('school_id')->unique())->get()->keyBy('id');

        foreach ($due as $notice) {
            $school = $schools->get($notice->school_id);

            if ($school && $school->isActive()) {
                // Dispatched inside the school, so the job carries its tenant.
                tenancy()->run($school, fn () => SendNoticeNotifications::dispatch($notice->id));
            }
        }

        $this->info("Announced {$due->count()} notice(s).");

        return self::SUCCESS;
    }
}
