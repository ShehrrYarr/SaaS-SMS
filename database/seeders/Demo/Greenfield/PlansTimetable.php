<?php

namespace Database\Seeders\Demo\Greenfield;

use App\Models\SubjectClass;
use App\Models\Timetable;
use App\Services\Academics\SubjectAllocator;
use App\Services\Timetable\BellSchedule;
use Database\Seeders\Demo\Dice;

/**
 * Who teaches what (subject allocations) and a clash-free week for every
 * section, drawn up so no teacher or room is double-booked.
 */
trait PlansTimetable
{
    /** Periods a week per subject, by class. */
    private const CURRICULUM = [
        'PREP' => ['ENG' => 7, 'URD' => 6, 'MATH' => 7, 'GK' => 4, 'ART' => 3],
        'G1' => ['ENG' => 6, 'URD' => 6, 'MATH' => 6, 'SCI' => 4, 'ISL' => 3, 'COMP' => 2, 'ART' => 2],
        'G2' => ['ENG' => 6, 'URD' => 6, 'MATH' => 6, 'SCI' => 4, 'ISL' => 3, 'COMP' => 2, 'ART' => 2],
        'G3' => ['ENG' => 6, 'URD' => 5, 'MATH' => 6, 'SCI' => 5, 'SST' => 4, 'ISL' => 3, 'COMP' => 2],
        'G4' => ['ENG' => 6, 'URD' => 5, 'MATH' => 6, 'SCI' => 5, 'SST' => 4, 'ISL' => 3, 'COMP' => 2],
        'G5' => ['ENG' => 6, 'URD' => 5, 'MATH' => 6, 'SCI' => 5, 'SST' => 4, 'ISL' => 3, 'COMP' => 2],
        'G6' => ['ENG' => 6, 'URD' => 5, 'MATH' => 6, 'SCI' => 6, 'SST' => 4, 'ISL' => 3, 'COMP' => 2],
        'G7' => ['ENG' => 6, 'URD' => 5, 'MATH' => 6, 'SCI' => 6, 'SST' => 4, 'ISL' => 3, 'COMP' => 2],
        'G8' => ['ENG' => 6, 'URD' => 5, 'MATH' => 6, 'SCI' => 6, 'SST' => 4, 'ISL' => 3, 'COMP' => 2],
    ];

    /**
     * Teachers by section and subject. Junior sections have a class teacher
     * for most subjects; from Grade 3 subjects have specialists.
     */
    private const TEACHING = [
        'PREP-A' => ['ENG' => 'mehwish', 'MATH' => 'mehwish', 'GK' => 'mehwish', 'ART' => 'mehwish', 'URD' => 'shazia'],
        'PREP-B' => ['ENG' => 'rabia', 'MATH' => 'rabia', 'GK' => 'rabia', 'ART' => 'rabia', 'URD' => 'shazia'],
        'G1-A' => ['ENG' => 'fariha', 'SCI' => 'fariha', 'ART' => 'fariha', 'MATH' => 'sana', 'URD' => 'shazia', 'ISL' => 'shazia', 'COMP' => 'hamza'],
        'G1-B' => ['ENG' => 'saba', 'SCI' => 'saba', 'ART' => 'saba', 'MATH' => 'sana', 'URD' => 'shazia', 'ISL' => 'shazia', 'COMP' => 'hamza'],
        'G2-A' => ['ENG' => 'hira', 'SCI' => 'hira', 'ART' => 'hira', 'MATH' => 'hira', 'URD' => 'ghazala', 'ISL' => 'ghazala', 'COMP' => 'hamza'],
        'G2-B' => ['ENG' => 'amna', 'SCI' => 'amna', 'ART' => 'amna', 'MATH' => 'amna', 'URD' => 'ghazala', 'ISL' => 'ghazala', 'COMP' => 'hamza'],
        'G3-A' => ['ENG' => 'sobia', 'URD' => 'aslam', 'MATH' => 'sana', 'SCI' => 'maryam', 'SST' => 'naveed', 'ISL' => 'zubair', 'COMP' => 'hamza'],
        'G3-B' => ['ENG' => 'sobia', 'URD' => 'aslam', 'MATH' => 'sana', 'SCI' => 'maryam', 'SST' => 'naveed', 'ISL' => 'zubair', 'COMP' => 'hamza'],
        'G4-A' => ['ENG' => 'sobia', 'URD' => 'aslam', 'MATH' => 'faisal', 'SCI' => 'maryam', 'SST' => 'naveed', 'ISL' => 'zubair', 'COMP' => 'hamza'],
        'G4-B' => ['ENG' => 'sobia', 'URD' => 'aslam', 'MATH' => 'faisal', 'SCI' => 'maryam', 'SST' => 'naveed', 'ISL' => 'zubair', 'COMP' => 'hamza'],
        'G5-A' => ['ENG' => 'usman', 'URD' => 'aslam', 'MATH' => 'faisal', 'SCI' => 'maryam', 'SST' => 'naveed', 'ISL' => 'zubair', 'COMP' => 'hamza'],
        'G5-B' => ['ENG' => 'usman', 'URD' => 'aslam', 'MATH' => 'faisal', 'SCI' => 'maryam', 'SST' => 'naveed', 'ISL' => 'zubair', 'COMP' => 'hamza'],
        'G6-A' => ['ENG' => 'sobia', 'URD' => 'shahid', 'MATH' => 'adeel', 'SCI' => 'kiran', 'SST' => 'shahid', 'ISL' => 'zubair', 'COMP' => 'hamza'],
        'G7-A' => ['ENG' => 'usman', 'URD' => 'shahid', 'MATH' => 'adeel', 'SCI' => 'kiran', 'SST' => 'shahid', 'ISL' => 'zubair', 'COMP' => 'hamza'],
        'G8-A' => ['ENG' => 'usman', 'URD' => 'shahid', 'MATH' => 'adeel', 'SCI' => 'kiran', 'SST' => 'shahid', 'ISL' => 'zubair', 'COMP' => 'hamza'],
    ];

    /** Subjects taught away from the home room. */
    private const SPECIAL_ROOMS = ['COMP' => 'LAB-C'];

    /** Core subjects go early in the day when they can. */
    private const MORNING = ['ENG', 'MATH', 'URD', 'SCI'];

    /** @var array<string, SubjectClass> "G3-A:MATH" => allocation */
    private array $allocations = [];

    private function planTimetable(): void
    {
        $this->as($this->admin, function () {
            $allocator = app(SubjectAllocator::class);

            foreach (self::CURRICULUM as $class => $subjects) {
                $rows = [];

                foreach ($subjects as $code => $periods) {
                    $teachers = [];

                    foreach ($this->classes[$class]->sections()->get() as $section) {
                        $teachers[(string) $section->id] = $this->staff[self::TEACHING[$class.'-'.$section->name][$code]]->id;
                    }

                    $rows[$this->subjects[$code]->id] = ['periods' => $periods, 'teachers' => $teachers];
                }

                $allocator->sync($this->year, $this->classes[$class], $rows);
            }

            $codes = array_flip(array_map(fn ($subject) => $subject->id, $this->subjects));
            $sectionKeys = array_flip(array_map(fn ($section) => $section->id, $this->sections));

            foreach (SubjectClass::query()->where('academic_year_id', $this->year->id)->get() as $allocation) {
                $this->allocations[$sectionKeys[$allocation->section_id].':'.$codes[$allocation->subject_id]] = $allocation;
            }

            // The solver already keeps teachers and rooms apart, so the slots go
            // in at once rather than one by one through Timetables::assign().
            $bells = app(BellSchedule::class)->periods()->keyBy('number');
            $rows = [];

            foreach ($this->solveTimetable() as [$section, $code, $day, $period]) {
                $room = self::SPECIAL_ROOMS[$code] ?? ($code === 'SCI' && (int) substr($section, 1, 1) >= 6 ? 'LAB-S' : self::HOME_ROOMS[$section]);

                $rows[] = [
                    'school_id' => $this->school->id,
                    'academic_year_id' => $this->year->id,
                    'subject_class_id' => $this->allocations[$section.':'.$code]->id,
                    'class_id' => $this->sections[$section]->class_id,
                    'section_id' => $this->sections[$section]->id,
                    'classroom_id' => $this->classrooms[$room]->id,
                    'day_of_week' => $day,
                    'period_number' => $period,
                    'start_time' => $bells[$period]['start'].':00',
                    'end_time' => $bells[$period]['end'].':00',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            Timetable::query()->insert($rows);

            activity('timetable')->causedBy($this->admin)
                ->withProperties(['sections' => count(self::TEACHING), 'slots' => count($rows)])
                ->log('Timetable drawn up');
        });
    }

    /**
     * Place every period of every section so no teacher is in two rooms at
     * once: most-loaded teachers first, spreading each subject across the
     * week. Retries with other orders until everything fits (or keeps the
     * best attempt).
     *
     * @return list<array{0: string, 1: string, 2: int, 3: int}> section, subject, day, period
     */
    private function solveTimetable(): array
    {
        $days = [1, 2, 3, 4, 5];
        $periods = range(1, 7);
        $units = [];
        $load = [];

        foreach (self::TEACHING as $section => $subjects) {
            foreach ($subjects as $code => $teacher) {
                $count = self::CURRICULUM[strstr($section, '-', true)][$code];
                $units[] = [$section, $code, $teacher, $count];
                $load[$teacher] = ($load[$teacher] ?? 0) + $count;
            }
        }

        $best = null;
        $dice = new Dice(7);

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $order = $dice->shuffle($units);
            usort($order, fn (array $a, array $b) => [$load[$b[2]], $b[3]] <=> [$load[$a[2]], $a[3]]);

            $sectionBusy = [];
            $teacherBusy = [];
            $placed = [];
            $missing = 0;

            foreach ($order as [$section, $code, $teacher, $count]) {
                $perDay = array_fill_keys($days, 0);

                for ($i = 0; $i < $count; $i++) {
                    $choice = null;
                    $score = PHP_INT_MAX;

                    foreach ($days as $day) {
                        foreach ($periods as $period) {
                            if (isset($sectionBusy[$section][$day][$period]) || isset($teacherBusy[$teacher][$day][$period])) {
                                continue;
                            }

                            $candidate = $perDay[$day] * 100
                                + (in_array($code, self::MORNING, true) ? $period : 8 - $period) * 3
                                + $dice->int(0, 5);

                            if ($candidate < $score) {
                                [$choice, $score] = [[$day, $period], $candidate];
                            }
                        }
                    }

                    if (! $choice) {
                        $missing++;

                        continue;
                    }

                    [$day, $period] = $choice;
                    $sectionBusy[$section][$day][$period] = true;
                    $teacherBusy[$teacher][$day][$period] = true;
                    $perDay[$day]++;
                    $placed[] = [$section, $code, $day, $period];
                }
            }

            if ($best === null || $missing < $best[0]) {
                $best = [$missing, $placed];
            }

            if ($missing === 0) {
                break;
            }
        }

        return $best[1];
    }
}
