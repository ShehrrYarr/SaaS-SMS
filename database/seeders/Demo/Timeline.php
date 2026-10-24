<?php

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use SplPriorityQueue;

/**
 * Replays a school's past in order. Each event runs with the clock set to
 * its moment, so timestamps, "today" checks, late fees, notifications and
 * the activity log all read as if it happened then. Events may schedule
 * later events; anything after the real present is dropped, since the demo
 * only shows what has already happened.
 */
final class Timeline
{
    /** @var SplPriorityQueue<array{0: int, 1: int}, array{0: CarbonImmutable, 1: Closure}> */
    private SplPriorityQueue $queue;

    private int $sequence = 0;

    private ?CarbonImmutable $current = null;

    public function __construct(public readonly CarbonImmutable $now)
    {
        $this->queue = new SplPriorityQueue;
    }

    /**
     * Schedule an event. Returns false when the moment is still to come.
     * Events placed before the one running now run straight after it.
     */
    public function at(CarbonInterface $moment, Closure $event): bool
    {
        $moment = CarbonImmutable::instance($moment);

        if ($moment->greaterThan($this->now)) {
            return false;
        }

        if ($this->current && $moment->lessThan($this->current)) {
            $moment = $this->current;
        }

        // Earliest first; ties in the order they were scheduled.
        $this->queue->insert([$moment, $event], [-$moment->getTimestamp(), -$this->sequence++]);

        return true;
    }

    /**
     * Run a one-off step at a moment (now if it is still to come).
     */
    public function travel(CarbonInterface $moment, Closure $step): mixed
    {
        $moment = CarbonImmutable::instance($moment);
        $moment = $moment->greaterThan($this->now) ? $this->now : $moment;

        self::freeze($moment);

        try {
            return $step();
        } finally {
            self::freeze($this->now);
        }
    }

    public function run(): void
    {
        while (! $this->queue->isEmpty()) {
            [$moment, $event] = $this->queue->extract();

            $this->current = $moment;
            self::freeze($moment);

            $event();
        }

        $this->current = null;
        self::freeze($this->now);
    }

    /**
     * Stop the clock at a moment, in the app's timezone so stored timestamps
     * stay in UTC.
     */
    public static function freeze(CarbonInterface $moment): void
    {
        Carbon::setTestNow(CarbonImmutable::instance($moment)->setTimezone(config('app.timezone')));
    }
}
