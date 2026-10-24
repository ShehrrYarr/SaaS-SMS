<?php

namespace Database\Seeders\Demo;

use App\Models\AcademicYear;
use App\Models\School;
use App\Models\User;
use App\Services\Fees\LateFees;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;

/**
 * Greenfield Academy, Lahore: the full demo school. Built by replaying its
 * first months on the platform through the app's own services, so every
 * record (and every notification and audit entry) is one the app itself
 * would have made:
 *
 *  - joined about six weeks before the academic year began; the year starts
 *    on the first of the month two months back, so there are always two to
 *    three months of history whatever day it is built;
 *  - two campuses, nine classes, fifteen sections, ~230 students with their
 *    families, 26 staff, a full timetable;
 *  - daily attendance, staff and student leave, a published monthly test and
 *    mid-term, homework, monthly invoices and payments, payroll, expenses,
 *    library loans, bus routes, hostel rooms and notices.
 *
 * $scale shrinks the student body (tests use a small school).
 */
final class GreenfieldAcademy
{
    use Greenfield\AdmitsStudents;
    use Greenfield\BillsFees;
    use Greenfield\HiresStaff;
    use Greenfield\KeepsAccounts;
    use Greenfield\PlansTimetable;
    use Greenfield\PostsNotices;
    use Greenfield\RecordsAttendance;
    use Greenfield\RunsExams;
    use Greenfield\RunsHostel;
    use Greenfield\RunsLibrary;
    use Greenfield\RunsPayroll;
    use Greenfield\RunsTransport;
    use Greenfield\SetsHomework;
    use Greenfield\SetsUpSchool;

    public const SLUG = 'greenfield';

    public const TIMEZONE = 'Asia/Karachi';

    /** ISO weekdays the school is closed. */
    private const WEEKEND = [6, 7];

    private Timeline $timeline;

    private Dice $dice;

    /** The school's today (start of day, school time). */
    private CarbonImmutable $today;

    private CarbonImmutable $yearStart;

    private CarbonImmutable $yearEnd;

    /** When the school signed up. */
    private CarbonImmutable $onboarded;

    private School $school;

    private User $admin;

    private AcademicYear $year;

    public function __construct(
        private readonly string $password,
        private readonly float $scale = 1.0,
    ) {}

    public function build(CarbonImmutable $now): School
    {
        $this->dice = new Dice(2026);
        $this->timeline = new Timeline($now);
        $this->today = $now->setTimezone(self::TIMEZONE)->startOfDay();
        $this->yearStart = $this->today->startOfMonth()->subMonthsNoOverflow(2);
        $this->yearEnd = $this->yearStart->addMonthsNoOverflow(11)->subDay();
        $this->onboarded = $this->yearStart->subDays(45)->setTime(10, 0);

        $this->at($this->onboarded, fn () => $this->createSchool());
        $this->at($this->onboarded->addHour(), fn () => $this->setUpStructure());
        $this->at($this->onboarded->addHours(2), fn () => $this->planSubscription());
        $this->at($this->onboarded->addDay()->setTime(10, 0), fn () => $this->hireStaff());
        $this->at($this->onboarded->addDay()->setTime(15, 0), fn () => $this->setUpFees());
        $this->at($this->onboarded->addDays(2)->setTime(9, 0), function () {
            $this->planAccounts();
            $this->planNotices();
        });
        $this->at($this->onboarded->addDays(2)->setTime(11, 0), fn () => $this->setUpLibrary());
        $this->at($this->onboarded->addDays(3)->setTime(11, 0), fn () => $this->admitStudents());
        $this->at($this->onboarded->addDays(4)->setTime(10, 0), fn () => $this->planTimetable());
        $this->at($this->onboarded->addDays(6)->setTime(10, 0), fn () => $this->setUpTransport());
        $this->at($this->onboarded->addDays(6)->setTime(12, 0), fn () => $this->setUpHostel());
        $this->at($this->yearStart->subDay()->setTime(16, 0), fn () => $this->startServices());
        $this->at($this->yearStart->subDay()->setTime(18, 0), fn () => $this->planTheYear());

        $this->timeline->run();

        // Last night's late-fee run, in case it is not 1 a.m. yet.
        app(LateFees::class)->applyOverdue();

        return $this->school;
    }

    /**
     * The day before term: discounts, bus seats and hostel beds.
     */
    private function startServices(): void
    {
        $this->giveDiscounts();
        $this->bookBuses();
        $this->allocateRooms();
    }

    /**
     * Everything that happens once the year starts.
     */
    private function planTheYear(): void
    {
        $this->planLeave();
        $this->planAttendance();
        $this->planNightlyJobs();
        $this->planInvoices();
        $this->planPayroll();
        $this->planExams();
        $this->planHomework();
        $this->planLibrary();
        $this->planTransportChanges();
        $this->planHostelChanges();
        $this->planDepartures();
    }

    /**
     * Schedule an event; false when its moment is still to come.
     */
    private function at(CarbonInterface $moment, Closure $event): bool
    {
        return $this->timeline->at($moment, $event);
    }

    /**
     * A time on a day, school time.
     */
    private function on(CarbonInterface $day, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($day->format('Y-m-d').' '.$time, self::TIMEZONE);
    }

    /**
     * A mutable date for the app's services.
     */
    private function date(CarbonInterface $day): Carbon
    {
        return Carbon::instance($day)->setTimezone(self::TIMEZONE);
    }

    /**
     * Run a step signed in as someone, as their request would be.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function as(User $user, Closure $callback): mixed
    {
        $previous = auth()->user();
        auth()->setUser($user);

        try {
            return $callback();
        } finally {
            $previous ? auth()->setUser($previous) : auth()->forgetUser();
        }
    }

    private function isSchoolDay(CarbonInterface $day): bool
    {
        return ! in_array($day->isoWeekday(), self::WEEKEND, true);
    }

    /**
     * The school day on or after a date (on or before it with $back).
     */
    private function schoolDay(CarbonInterface $day, bool $back = false): CarbonImmutable
    {
        $day = CarbonImmutable::instance($day)->setTimezone(self::TIMEZONE)->startOfDay();

        while (! $this->isSchoolDay($day)) {
            $day = $back ? $day->subDay() : $day->addDay();
        }

        return $day;
    }

    /**
     * Count school days forwards (or back, for a negative count) from a school day.
     */
    private function addSchoolDays(CarbonInterface $day, int $count): CarbonImmutable
    {
        $day = $this->schoolDay($day, $count < 0);

        for ($i = 0; $i < abs($count); $i++) {
            $day = $this->schoolDay($count < 0 ? $day->subDay() : $day->addDay(), $count < 0);
        }

        return $day;
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function schoolDaysBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        $days = [];

        for ($day = CarbonImmutable::instance($from)->startOfDay(); $day->lte($to); $day = $day->addDay()) {
            if ($this->isSchoolDay($day)) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /**
     * Whether a moment has already happened.
     */
    private function happened(CarbonInterface $moment): bool
    {
        return $moment->lte($this->timeline->now);
    }

    private function scaled(int $count, int $minimum = 1): int
    {
        return max($minimum, (int) round($count * $this->scale));
    }
}
