<?php

namespace App\Services\Timetable;

use App\Enums\AcademicPeriodStatus;
use App\Models\AcademicYear;
use App\Models\Timetable;
use App\Services\Attendance\SchoolCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The school day: numbered teaching periods with start and end times, kept
 * in the school's settings (timetable.periods). Gaps between periods are
 * breaks. Every timetable slot takes its times from its period.
 */
class BellSchedule
{
    public function __construct(private readonly SchoolCalendar $calendar) {}

    /**
     * @return Collection<int, array{number: int, start: string, end: string}>
     */
    public function periods(): Collection
    {
        return collect(tenant()?->setting('timetable.periods', []) ?? [])
            ->filter(fn ($period) => is_array($period) && isset($period['number'], $period['start'], $period['end']))
            ->map(fn (array $period) => ['number' => (int) $period['number'], 'start' => (string) $period['start'], 'end' => (string) $period['end']])
            ->sortBy('start')
            ->values();
    }

    /**
     * @return array{number: int, start: string, end: string}|null
     */
    public function period(int $number): ?array
    {
        return $this->periods()->firstWhere('number', $number);
    }

    /**
     * ISO weekdays the school teaches, in the school's week order.
     *
     * @return list<int>
     */
    public function days(): array
    {
        $first = (int) (tenant()?->setting('week_starts_on') ?? 1);
        $off = $this->calendar->weeklyOff();
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $day = ($first - 1 + $i) % 7 + 1;

            if (! in_array($day, $off, true)) {
                $days[] = $day;
            }
        }

        return $days;
    }

    public static function dayName(int $isoDay, bool $short = false): string
    {
        // 2024-01-01 was a Monday.
        return Carbon::create(2024, 1, $isoDay)->translatedFormat($short ? 'D' : 'l');
    }

    /**
     * "08:00 AM – 08:40 AM" in the school's time format.
     *
     * @param  array{start: string, end: string}  $period
     */
    public static function label(array $period): string
    {
        $format = tenant()?->setting('time_format') ?? 'h:i A';

        return Carbon::parse($period['start'])->format($format).' – '.Carbon::parse($period['end'])->format($format);
    }

    /**
     * Minutes between the end of one period and the start of the next.
     */
    public static function gapMinutes(array $before, array $after): int
    {
        return (int) Carbon::parse($before['end'])->diffInMinutes(Carbon::parse($after['start']), false);
    }

    /**
     * A day of equal periods: $count periods of $minutes from $start, with one
     * break of $breakMinutes after period $breakAfter (0 for none).
     *
     * @return list<array{number: int, start: string, end: string}>
     */
    public static function generate(string $start, int $minutes, int $count, int $breakAfter = 0, int $breakMinutes = 0): array
    {
        $time = Carbon::parse($start);
        $periods = [];

        for ($number = 1; $number <= $count; $number++) {
            $end = $time->copy()->addMinutes($minutes);
            $periods[] = ['number' => $number, 'start' => $time->format('H:i'), 'end' => $end->format('H:i')];
            $time = $end->copy()->addMinutes($number === $breakAfter ? $breakMinutes : 0);
        }

        return $periods;
    }

    /**
     * Slots that would be cleared by saving these periods (their period
     * number no longer exists).
     *
     * @param  list<array{start: string, end: string}>  $periods
     */
    public function slotsLostBy(array $periods): int
    {
        return $this->liveSlots()->where('period_number', '>', count($periods))->count();
    }

    /**
     * Save the school day. Periods are renumbered in time order; slots of the
     * current and upcoming years keep their period number and take its new
     * times, and those of a period that no longer exists are cleared (archived
     * years keep their timetables as they were). Returns how many slots were
     * cleared.
     *
     * @param  list<array{start: string, end: string}>  $periods
     */
    public function save(array $periods): int
    {
        $periods = collect($periods)
            ->map(fn (array $period) => ['start' => Carbon::parse($period['start'])->format('H:i'), 'end' => Carbon::parse($period['end'])->format('H:i')])
            ->sortBy('start')
            ->values();

        $errors = [];

        foreach ($periods as $index => $period) {
            if ($period['end'] <= $period['start']) {
                $errors["periods.{$index}.end"] = __('A period must end after it starts.');
            }

            $previous = $periods[$index - 1] ?? null;

            if ($previous && $period['start'] < $previous['end']) {
                $errors["periods.{$index}.start"] = __('This period overlaps the one before it.');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $numbered = $periods->map(fn (array $period, int $index) => ['number' => $index + 1, ...$period])->all();
        $school = tenant();

        return DB::transaction(function () use ($school, $numbered) {
            $settings = $school->settings ?? [];
            // Replace the list outright; merging would keep periods that were removed.
            $settings['timetable']['periods'] = $numbered;
            $school->update(['settings' => $settings]);

            $cleared = $this->liveSlots()->where('period_number', '>', count($numbered))->delete();

            foreach ($numbered as $period) {
                $this->liveSlots()->where('period_number', $period['number'])
                    ->update(['start_time' => $period['start'].':00', 'end_time' => $period['end'].':00']);
            }

            activity('timetable')->performedOn($school)->causedBy(auth()->user())
                ->withProperties(['periods' => $numbered, 'cleared_slots' => $cleared])
                ->log('School day updated');

            return $cleared;
        });
    }

    /**
     * Slots of years that are not archived.
     *
     * @return Builder<Timetable>
     */
    private function liveSlots(): Builder
    {
        return Timetable::query()->whereIn('academic_year_id', AcademicYear::query()->where('status', '!=', AcademicPeriodStatus::Archived)->select('id'));
    }
}
