<?php

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoSchools;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * Builds the demo schools (Greenfield Academy and three smaller ones) on any
 * server. --fresh deletes them and builds them again, e.g. nightly on a demo
 * site. Only the demo schools are ever touched.
 */
#[AsCommand(name: 'demo:seed')]
class SeedDemo extends Command
{
    use ConfirmableTrait;

    protected $signature = 'demo:seed
        {--fresh : Delete the demo schools and build them again}
        {--password= : Sign-in password for every demo account (default "password")}
        {--only-greenfield : Skip the smaller schools}
        {--force : Run in production without asking}';

    protected $description = 'Build the demo schools with a term\'s worth of data';

    public function handle(): int
    {
        if (! $this->confirmToProceed('The demo schools ('.implode(', ', DemoSchools::slugs()).') will be '.($this->option('fresh') ? 'deleted and rebuilt' : 'created').'.')) {
            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: DemoSchools::PASSWORD);

        if (mb_strlen($password) < 8) {
            $this->error('Use a password of at least 8 characters.');

            return self::FAILURE;
        }

        $this->info('Building the demo; Greenfield Academy takes about three minutes…');

        $outcome = (new DemoSchools($password, network: ! $this->option('only-greenfield'), report: fn (string $line) => $this->line($line)))
            ->build((bool) $this->option('fresh'));

        if (! in_array('built', $outcome, true)) {
            $this->warn('Nothing new built. Use --fresh to rebuild the demo schools.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Sign in at /greenfield/login with the demo password, as:');
        $this->table(['Portal', 'Username'], [
            ['School admin', 'admin'],
            ['Teacher (class teacher of Grade 3-A)', 'sana.malik'],
            ['Teacher + parent', 'maryam.iqbal'],
            ['Exam coordinator (custom role)', 'faisal.mahmood'],
            ['Student', 'ayesha.khan, zain.khan'],
            ['Parent of both', 'imran.khan'],
            ['HR', 'hina.hr'],
            ['Accountant', 'omar.accounts'],
            ['Librarian', 'laila.library'],
            ['Transport manager', 'bilal.transport'],
            ['Hostel wardens', 'nadia.hostel, sajid.iqbal'],
        ]);

        return self::SUCCESS;
    }
}
