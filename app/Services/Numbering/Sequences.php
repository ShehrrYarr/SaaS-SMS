<?php

namespace App\Services\Numbering;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Generated document numbers for the active school: "ADM-0042", "EMP-0007".
 *
 * The counter row is locked until the surrounding transaction commits, so
 * concurrent admissions never get the same number. Numbers already taken
 * (typed in by hand, or imported) are skipped.
 */
class Sequences
{
    public const PAD = 4;

    /**
     * Take the next free number.
     *
     * @param  Closure(string): bool  $taken  whether a number is already used
     */
    public function next(string $key, string $prefix, Closure $taken, int $pad = self::PAD): string
    {
        return DB::transaction(function () use ($key, $prefix, $taken, $pad) {
            $schoolId = tenant()->getKey();

            DB::table('school_sequences')->insertOrIgnore([
                'school_id' => $schoolId,
                'key' => $key,
                'last_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $value = (int) DB::table('school_sequences')
                ->where('school_id', $schoolId)
                ->where('key', $key)
                ->lockForUpdate()
                ->value('last_value');

            do {
                $number = $this->format($prefix, ++$value, $pad);
            } while ($taken($number));

            DB::table('school_sequences')
                ->where('school_id', $schoolId)
                ->where('key', $key)
                ->update(['last_value' => $value, 'updated_at' => now()]);

            return $number;
        });
    }

    /**
     * The number next() would most likely return, without taking it (for
     * form placeholders).
     *
     * @param  Closure(string): bool  $taken
     */
    public function peek(string $key, string $prefix, Closure $taken, int $pad = self::PAD): string
    {
        $value = (int) DB::table('school_sequences')
            ->where('school_id', tenant()->getKey())
            ->where('key', $key)
            ->value('last_value');

        do {
            $number = $this->format($prefix, ++$value, $pad);
        } while ($taken($number));

        return $number;
    }

    private function format(string $prefix, int $value, int $pad): string
    {
        return $prefix.str_pad((string) $value, $pad, '0', STR_PAD_LEFT);
    }
}
