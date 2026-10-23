<?php

namespace App\Services\Fees;

use App\Enums\FeeFrequency;
use App\Models\AcademicYear;
use App\Models\FeeStructure;
use App\Services\Attendance\SchoolCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * When fees fall due. Invoices are billed per month ("billing period",
 * YYYY-MM):
 *
 *  - monthly fees every month of the academic year;
 *  - quarterly, half-yearly and yearly fees every 3, 6 or 12 months
 *    counting from the month the academic year starts;
 *  - one-time fees in the month of their due date (any month when they
 *    have none), and only once per student.
 */
class FeeSchedule
{
    public const DEFAULT_DUE_DAY = 10;

    public function __construct(private readonly SchoolCalendar $calendar) {}

    public static function isPeriod(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1;
    }

    /**
     * First day of a billing period.
     */
    public static function periodStart(string $period): Carbon
    {
        return Carbon::createFromFormat('!Y-m', $period);
    }

    /**
     * "October 2026".
     */
    public static function periodLabel(string $period): string
    {
        return static::periodStart($period)->translatedFormat('F Y');
    }

    /**
     * The academic year a billing period belongs to.
     */
    public function yearFor(string $period): ?AcademicYear
    {
        return $this->calendar->yearFor(static::periodStart($period));
    }

    public function isDue(FeeStructure $fee, string $period, ?AcademicYear $year): bool
    {
        $month = static::periodStart($period);

        if ($fee->frequency === FeeFrequency::OneTime) {
            return $fee->due_date === null || $fee->due_date->format('Y-m') === $period;
        }

        if ($year && ($month->lt($year->start_date->copy()->startOfMonth()) || $month->gt($year->end_date->copy()->startOfMonth()))) {
            return false;
        }

        $offset = $year
            ? ($month->year * 12 + $month->month) - ($year->start_date->year * 12 + $year->start_date->month)
            : $month->month - 1;

        return match ($fee->frequency) {
            FeeFrequency::Quarterly => $offset % 3 === 0,
            FeeFrequency::HalfYearly => $offset % 6 === 0,
            FeeFrequency::Yearly => $offset % 12 === 0,
            default => true,
        };
    }

    /**
     * Active fees of an academic year (and those set for every year),
     * optionally only those that apply to one class.
     *
     * @return Collection<int, FeeStructure>
     */
    public function feesFor(?AcademicYear $year, ?int $classId = null): Collection
    {
        return FeeStructure::query()
            ->with('schoolClass')
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('academic_year_id')->when($year, fn ($query) => $query->orWhere('academic_year_id', $year->id)))
            ->when($classId, fn ($query) => $query->where(fn ($query) => $query->whereNull('class_id')->orWhere('class_id', $classId)))
            ->orderBy('title')
            ->get();
    }

    /**
     * Fees due in a period.
     *
     * @return Collection<int, FeeStructure>
     */
    public function dueIn(string $period, ?AcademicYear $year = null, ?int $classId = null): Collection
    {
        $year ??= $this->yearFor($period);

        return $this->feesFor($year, $classId)->filter(fn (FeeStructure $fee) => $this->isDue($fee, $period, $year))->values();
    }

    /**
     * Default due date for a period's invoice: the earliest due day among
     * the fees (or a one-time fee's own date in that month), else the 10th.
     *
     * @param  Collection<int, FeeStructure>  $fees
     */
    public function dueDateFor(string $period, Collection $fees): Carbon
    {
        $month = static::periodStart($period);

        $days = $fees->map(fn (FeeStructure $fee) => $fee->frequency === FeeFrequency::OneTime
            ? ($fee->due_date?->format('Y-m') === $period ? $fee->due_date->day : null)
            : $fee->due_day)
            ->filter()
            ->map(fn ($day) => min((int) $day, $month->daysInMonth));

        return $month->copy()->day($days->min() ?? min(self::DEFAULT_DUE_DAY, $month->daysInMonth));
    }
}
