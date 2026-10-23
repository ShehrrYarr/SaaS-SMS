<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    use EnumOptions;

    case Present = 'present';
    case Absent = 'absent';
    case Late = 'late';
    case Excused = 'excused';

    /**
     * Attendance rate in percent from counts per status: present and late
     * days over the days marked, leaving out excused days (approved leave
     * doesn't count against a student). Null when there is nothing to rate.
     *
     * @param  iterable<string, int>  $counts  status value => days
     */
    public static function rate(iterable $counts): ?float
    {
        $counts = collect($counts);
        $attended = (int) $counts->get(self::Present->value, 0) + (int) $counts->get(self::Late->value, 0);
        $counted = (int) $counts->sum() - (int) $counts->get(self::Excused->value, 0);

        return $counted > 0 ? round($attended / $counted * 100, 1) : null;
    }

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Present => 'success',
            self::Absent => 'danger',
            self::Late => 'warning',
            self::Excused => 'info',
        };
    }
}
