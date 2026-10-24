<?php

namespace Database\Seeders\Demo;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seeded randomness, so the demo comes out the same every time it is built
 * (only the dates move with today).
 */
final class Dice
{
    private Randomizer $random;

    public function __construct(int $seed = 2026)
    {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    public function int(int $min, int $max): int
    {
        return $this->random->getInt($min, $max);
    }

    /**
     * True $percent times in a hundred.
     */
    public function chance(float $percent): bool
    {
        return $this->random->getInt(1, 10000) <= (int) round($percent * 100);
    }

    /**
     * @template T
     *
     * @param  array<array-key, T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        $keys = array_keys($items);

        return $items[$keys[$this->random->getInt(0, count($keys) - 1)]];
    }

    /**
     * @template T
     *
     * @param  array<array-key, T>  $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        return $this->random->shuffleArray(array_values($items));
    }

    /**
     * @template T
     *
     * @param  array<array-key, T>  $items
     * @return list<T>
     */
    public function sample(array $items, int $count): array
    {
        return array_slice($this->shuffle($items), 0, max(0, $count));
    }

    /**
     * A key chosen in proportion to its weight.
     *
     * @param  array<array-key, int|float>  $weights
     */
    public function weighted(array $weights): int|string
    {
        $roll = $this->float() * array_sum($weights);

        foreach ($weights as $key => $weight) {
            if (($roll -= $weight) <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    /**
     * A number in [0, 1).
     */
    public function float(): float
    {
        return $this->random->getInt(0, 999_999_999) / 1_000_000_000;
    }

    /**
     * Roughly bell-shaped around $mean (Box–Muller).
     */
    public function normal(float $mean, float $deviation): float
    {
        $u = max($this->float(), 1e-9);
        $v = $this->float();

        return $mean + $deviation * sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    }

    /**
     * A digit string, e.g. for phone and reference numbers.
     */
    public function digits(int $length): string
    {
        $digits = '';

        for ($i = 0; $i < $length; $i++) {
            $digits .= $this->random->getInt(0, 9);
        }

        return $digits;
    }
}
