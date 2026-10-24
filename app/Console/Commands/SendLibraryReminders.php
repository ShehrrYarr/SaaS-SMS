<?php

namespace App\Console\Commands;

use App\Models\BookIssue;
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

            tenancy()->run($school, function () use ($school, $library, &$sent) {
                $today = $school->today();
                $format = $school->setting('date_format');

                BookIssue::query()->out()->with(['book' => fn ($query) => $query->withTrashed(), 'borrower'])
                    ->whereDate('due_on', $today->copy()->addDay()->toDateString())
                    ->whereNull('reminded_at')
                    ->chunkById(200, function ($issues) use ($library, $format, &$sent) {
                        foreach ($issues as $issue) {
                            $library->tell($issue,
                                __('Library book due tomorrow: :title', ['title' => $issue->book?->title]),
                                __('Please return it by :date.', ['date' => $issue->due_on->format($format)]),
                                'warning');
                            $issue->update(['reminded_at' => now()]);
                            $sent++;
                        }
                    });

                BookIssue::query()->overdue($today)->with(['book' => fn ($query) => $query->withTrashed(), 'borrower'])
                    ->whereNull('overdue_notified_at')
                    ->chunkById(200, function ($issues) use ($library, $format, &$sent) {
                        $fine = Library::settings()['fine_per_day'];

                        foreach ($issues as $issue) {
                            $library->tell($issue,
                                __('Library book overdue: :title', ['title' => $issue->book?->title]),
                                $fine > 0
                                    ? __('It was due on :date. A fine is charged for each day late.', ['date' => $issue->due_on->format($format)])
                                    : __('It was due on :date. Please return it.', ['date' => $issue->due_on->format($format)]),
                                'danger');
                            $issue->update(['overdue_notified_at' => now()]);
                            $sent++;
                        }
                    });
            });
        });

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
