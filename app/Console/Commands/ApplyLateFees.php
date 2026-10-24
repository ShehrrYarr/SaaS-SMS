<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Fees\LateFees;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

/**
 * Daily: marks invoices that have passed their due date as overdue and
 * charges late fees where the school has them switched on. Each school is
 * handled in its own timezone's "today".
 */
#[AsCommand(name: 'fees:apply-late-fees')]
class ApplyLateFees extends Command
{
    protected $signature = 'fees:apply-late-fees';

    protected $description = 'Mark overdue invoices and charge late fees';

    public function handle(LateFees $lateFees): int
    {
        $updated = 0;

        School::query()->each(function (School $school) use ($lateFees, &$updated) {
            if (! $school->isActive()) {
                return;
            }

            $updated += tenancy()->run($school, fn () => $lateFees->applyOverdue($school->today()));
        });

        $this->info("Updated {$updated} invoice(s).");

        return self::SUCCESS;
    }
}
