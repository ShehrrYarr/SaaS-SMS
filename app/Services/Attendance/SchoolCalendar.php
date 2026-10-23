<?php

namespace App\Services\Attendance;

use App\Models\AcademicYear;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;

/**
 * Working days of the active school: every day except its weekly off days
 * (School settings → Attendance).
 */
class SchoolCalendar
{
    /**
     * ISO weekdays the school is closed (1 = Monday ... 7 = Sunday).
     *
     * @return list<int>
     */
    public function weeklyOff(): array
    {
        $days = tenant()->setting('attendance.weekly_off', '');
        $days = is_array($days) ? $days : explode(',', (string) $days);

        return array_values(array_filter(array_map('intval', $days), fn (int $day) => $day >= 1 && $day <= 7));
    }

    public function isWeeklyOff(CarbonInterface $date): bool
    {
        return in_array($date->isoWeekday(), $this->weeklyOff(), true);
    }

    /**
     * School days from $from to $to, inclusive.
     *
     * @return list<Carbon>
     */
    public function schoolDays(CarbonInterface $from, CarbonInterface $to): array
    {
        if ($to->lt($from)) {
            return [];
        }

        $days = [];

        foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $day) {
            if (! $this->isWeeklyOff($day)) {
                $days[] = Carbon::instance($day);
            }
        }

        return $days;
    }

    public function countSchoolDays(CarbonInterface $from, CarbonInterface $to): int
    {
        return count($this->schoolDays($from, $to));
    }

    /**
     * The academic year a date falls in, or the current one.
     */
    public function yearFor(CarbonInterface $date): ?AcademicYear
    {
        return AcademicYear::query()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->orderByDesc('start_date')
            ->first()
            ?? AcademicYear::query()->current()->first();
    }
}
