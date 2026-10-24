<?php

namespace Database\Seeders\Demo;

use App\Models\Plan;
use App\Models\School;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Builds the demo schools: Greenfield Academy in full, and three smaller
 * schools for the console. Schools that already exist are kept unless
 * $fresh, which deletes them and builds them again.
 *
 * Every demo account signs in with the same password and isn't asked to
 * change it. Notifications older than four days are marked read.
 */
final class DemoSchools
{
    public const PASSWORD = 'password';

    public function __construct(
        private readonly string $password = self::PASSWORD,
        private readonly float $scale = 1.0,
        private readonly bool $network = true,
        private readonly ?Closure $report = null,
    ) {}

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return [GreenfieldAcademy::SLUG, ...array_keys(NetworkSchools::SCHOOLS)];
    }

    /**
     * @return array<string, string> slug => built | kept
     */
    public function build(bool $fresh = false): array
    {
        if (Plan::query()->doesntExist()) {
            throw new RuntimeException('There are no plans yet. Run: php artisan db:seed --class=PlanSeeder');
        }

        (new PermissionCatalogSeeder)->run();

        $clock = Carbon::getTestNow();
        $now = CarbonImmutable::instance($clock ?? CarbonImmutable::now());
        $queue = config('queue.default');
        $hash = Hash::make($this->password);
        $hasher = Hash::driver();
        $outcome = [];

        // Jobs (invoice runs, notifications) run inline, and the hundreds of
        // throwaway first passwords hash quickly; every account gets $hash at the end.
        config(['queue.default' => 'sync']);

        if ($hasher instanceof BcryptHasher) {
            $hasher->setRounds(4);
        }

        try {
            foreach (self::slugs() as $slug) {
                if (! $this->network && $slug !== GreenfieldAcademy::SLUG) {
                    continue;
                }

                $existing = School::query()->withTrashed()->where('slug', $slug)->first();

                if ($existing && ! $fresh) {
                    $outcome[$slug] = 'kept';
                    $this->say("/{$slug}: already there, kept.");

                    continue;
                }

                if ($existing) {
                    (new DemoPurger)->purge($existing);
                }

                $started = microtime(true);

                $school = $slug === GreenfieldAcademy::SLUG
                    ? (new GreenfieldAcademy($this->password, $this->scale))->build($now)
                    : (new NetworkSchools($this->password, $this->scale))->build($slug, $now);

                Timeline::freeze($now);
                $this->finish($school, $hash);

                $outcome[$slug] = 'built';
                $this->say(sprintf('/%s: %s built in %ds.', $slug, $school->name, microtime(true) - $started));
            }
        } finally {
            Carbon::setTestNow($clock);
            config(['queue.default' => $queue]);

            if ($hasher instanceof BcryptHasher) {
                $hasher->setRounds((int) config('hashing.bcrypt.rounds', 12));
            }

            tenancy()->forget();
            auth()->forgetUser();
        }

        return $outcome;
    }

    private function finish(School $school, string $hash): void
    {
        tenancy()->run($school, function () use ($hash) {
            User::query()->update(['password' => $hash, 'must_change_password' => false]);

            DB::table('notifications')
                ->where('notifiable_type', (new User)->getMorphClass())
                ->whereIn('notifiable_id', User::query()->select('id'))
                ->whereNull('read_at')
                ->where('created_at', '<', now()->subDays(4))
                ->update(['read_at' => DB::raw('created_at')]);
        });
    }

    private function say(string $line): void
    {
        if ($this->report) {
            ($this->report)($line);
        }
    }
}
