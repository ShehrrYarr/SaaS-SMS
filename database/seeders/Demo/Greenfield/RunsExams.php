<?php

namespace Database\Seeders\Demo\Greenfield;

use App\Enums\ExamStatus;
use App\Enums\Gender;
use App\Models\ExamSchedule;
use App\Models\Examination;
use App\Models\StudentEnrollment;
use App\Models\Term;
use App\Models\User;
use App\Services\Exams\ExamSchedules;
use App\Services\Exams\Gradebook;
use App\Services\Exams\ResultPublisher;
use Carbon\CarbonImmutable;

/**
 * The exam calendar: a monthly test and the mid-term, both marked and
 * published; the next monthly test on the date sheet; the final term still
 * a draft.
 */
trait RunsExams
{
    /** Subjects in the monthly tests. */
    private const TEST_SUBJECTS = ['ENG', 'URD', 'MATH', 'SCI', 'GK'];

    /** @var array<int, array<int, array<string, array{0: float, 1: float}>>> exam id => student id => subject => [obtained, max] */
    private array $results = [];

    /** @var array<int, array<string, float>> student id => subject => how much better or worse than usual */
    private array $leanings = [];

    private function planExams(): void
    {
        $terms = Term::query()->where('academic_year_id', $this->year->id)->orderBy('sort_order')->get()->values();
        $coordinator = $this->people['faisal'];

        // First Monthly Test: three mornings in the fourth week.
        $testStart = $this->addSchoolDays($this->yearStart, 18);
        $this->planExam('First Monthly Test', 'Short tests in the core subjects.', $terms[0]->id, $testStart, $this->addSchoolDays($testStart, 2),
            core: true, created: $this->on($this->addSchoolDays($testStart, -10), '10:30'), publishAfter: 6, by: $coordinator);

        // Mid-term: the week before last.
        $midEnd = $this->addSchoolDays($this->today, -5);
        $midStart = $this->addSchoolDays($midEnd, -4);
        $this->planExam('Mid-Term Examination', 'First term examination in every subject. Report cards follow.', $terms[0]->id, $midStart, $midEnd,
            core: false, created: $this->on($this->addSchoolDays($midStart, -15), '11:00'), publishAfter: 4, by: $coordinator, remarks: true);

        // Second Monthly Test: on the date sheet, not yet sat.
        $nextStart = $this->addSchoolDays($this->today, 8);
        $this->planExam('Second Monthly Test', 'Short tests in the core subjects.', $terms[0]->id, $nextStart, $this->addSchoolDays($nextStart, 2),
            core: true, created: $this->on($this->addSchoolDays($this->today, -2), '12:00'), publishAfter: null, by: $coordinator);

        // Final term: planned, no date sheet yet.
        $finalStart = $this->schoolDay($this->yearEnd->subWeeks(5));

        Examination::query()->create([
            'name' => 'Final Term Examination',
            'description' => 'Annual examination; decides promotion to the next class.',
            'academic_year_id' => $this->year->id,
            'term_id' => $terms[1]->id ?? null,
            'start_date' => $finalStart->toDateString(),
            'end_date' => $this->addSchoolDays($finalStart, 9)->toDateString(),
            'status' => ExamStatus::Draft,
        ]);
    }

    private function planExam(string $name, string $description, int $termId, CarbonImmutable $start, CarbonImmutable $end, bool $core, CarbonImmutable $created, ?int $publishAfter, User $by, bool $remarks = false): void
    {
        $examId = null;
        $exam = function () use (&$examId): Examination {
            return Examination::query()->findOrFail($examId);
        };

        $this->at($created, function () use (&$examId, $name, $description, $termId, $start, $end, $core, $by) {
            $this->as($by, function () use (&$examId, $name, $description, $termId, $start, $end, $core) {
                $exam = Examination::query()->create([
                    'name' => $name,
                    'description' => $description,
                    'academic_year_id' => $this->year->id,
                    'term_id' => $termId,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'status' => ExamStatus::Draft,
                ]);

                $days = $this->schoolDaysBetween($start, $end);

                foreach (self::CURRICULUM as $class => $subjects) {
                    $codes = array_values(array_filter(array_keys($subjects), fn (string $code) => ! $core || in_array($code, self::TEST_SUBJECTS, true)));
                    $junior = self::CLASSES[$class][2] <= 2;
                    $rows = [];

                    foreach ($codes as $i => $code) {
                        $day = $days[(int) floor($i * count($days) / count($codes))];
                        $second = $i > 0 && $days[(int) floor(($i - 1) * count($days) / count($codes))]->isSameDay($day);

                        $rows[$this->subjects[$code]->id] = [
                            'exam_date' => $day->toDateString(),
                            'start_time' => $second ? '11:00' : '08:30',
                            'end_time' => $second ? ($core ? '11:45' : '12:30') : ($core ? '09:15' : '10:30'),
                            'classroom_id' => null,
                            'max_marks' => $core ? 25 : ($junior ? 50 : 100),
                            'passing_marks' => $core ? 10 : ($junior ? 20 : 33),
                        ];
                    }

                    app(ExamSchedules::class)->sync($exam, $this->classes[$class], $rows);
                }

                $exam->update(['status' => ExamStatus::Scheduled]);
                $examId = $exam->id;
            });
        });

        $this->at($this->on($start, '08:00'), fn () => $exam()->update(['status' => ExamStatus::Ongoing]));
        $this->at($this->on($end, '13:30'), fn () => $exam()->update(['status' => ExamStatus::Completed]));

        // Each teacher marks their own papers over the next three days.
        foreach (self::TEACHING as $section => $subjects) {
            foreach ($subjects as $code => $teacher) {
                $when = $this->on($this->addSchoolDays($end, $this->dice->int(1, 3)), sprintf('%02d:%02d', $this->dice->int(13, 17), $this->dice->int(0, 59)));

                $this->at($when, fn () => $this->enterMarks($exam(), $section, $code, $teacher));
            }
        }

        if ($remarks) {
            foreach (self::CLASS_TEACHERS as $section => $teacher) {
                $this->at($this->on($this->addSchoolDays($end, 3), '17:'.$this->dice->int(10, 50)), fn () => $this->writeRemarks($exam(), $section, $teacher));
            }
        }

        if ($publishAfter !== null) {
            $this->at($this->on($this->addSchoolDays($end, $publishAfter), '12:00'), fn () => app(ResultPublisher::class)->publish($exam(), $by));
        }
    }

    private function enterMarks(Examination $exam, string $sectionKey, string $code, string $teacherKey): void
    {
        $section = $this->sections[$sectionKey];
        $schedule = ExamSchedule::query()
            ->where('examination_id', $exam->id)
            ->where('class_id', $section->class_id)
            ->where('subject_id', $this->subjects[$code]->id)
            ->first();

        if (! $schedule) {
            return;
        }

        $max = (float) $schedule->max_marks;
        $roster = StudentEnrollment::query()
            ->where('academic_year_id', $exam->academic_year_id)
            ->where('class_id', $section->class_id)
            ->where('section_id', $section->id)
            ->pluck('student_profile_id');
        $entries = [];

        foreach ($roster as $studentId) {
            if ($this->dice->chance(1.2)) {
                $entries[$studentId] = ['absent' => true];

                continue;
            }

            $ability = $this->pupils[$studentId]['ability'] ?? 0.7;
            $leaning = $this->leanings[$studentId][$code] ??= $this->dice->normal(0, 0.07);
            $share = max(0.18, min(1.0, $ability + $leaning + $this->dice->normal(0, 0.06)));
            $marks = min($max, round($share * $max * 2) / 2);

            $entries[$studentId] = ['marks' => (string) $marks, 'absent' => false];
            $this->results[$exam->id][$studentId][$code] = [$marks, $max];
        }

        $teacher = $this->people[$teacherKey];

        $this->as($teacher, fn () => app(Gradebook::class)->save($exam, $schedule, $section, $entries, $teacher));
    }

    private function writeRemarks(Examination $exam, string $sectionKey, string $teacherKey): void
    {
        $section = $this->sections[$sectionKey];
        $remarks = [];

        foreach ($this->results[$exam->id] ?? [] as $studentId => $subjects) {
            $pupil = $this->pupils[$studentId] ?? null;

            if (! $pupil || $pupil['section'] !== $sectionKey) {
                continue;
            }

            $remarks[$studentId] = $this->remarkFor($pupil, $subjects);
        }

        $teacher = $this->people[$teacherKey];

        $this->as($teacher, fn () => app(Gradebook::class)->saveRemarks($exam, $this->classes[strstr($sectionKey, '-', true)], $section, $remarks, $teacher));
    }

    /**
     * A class teacher's comment from the student's results.
     *
     * @param  array<string, mixed>  $pupil
     * @param  array<string, array{0: float, 1: float}>  $subjects
     */
    private function remarkFor(array $pupil, array $subjects): string
    {
        if ($pupil['student']->user?->username === 'ayesha.khan') {
            return 'Ayesha is attentive and asks thoughtful questions. Keep practising times tables at home.';
        }

        $shares = array_map(fn (array $result) => $result[0] / max($result[1], 1), $subjects);
        $percent = array_sum(array_column($subjects, 0)) / max(array_sum(array_column($subjects, 1)), 1) * 100;
        asort($shares);
        $weakest = $this->subjects[array_key_first($shares)]->name;
        $strongest = $this->subjects[array_key_last($shares)]->name;
        [$he, $his] = $pupil['gender'] === Gender::Female ? ['she', 'her'] : ['he', 'his'];
        $name = $pupil['first'];

        $options = match (true) {
            $percent >= 85 => [
                "{$name} is an outstanding student and a pleasure to teach. Keep it up!",
                "Excellent work this term, {$name}. ".ucfirst($his)." {$strongest} is especially strong.",
            ],
            $percent >= 70 => [
                "{$name} has done well this term. A little more practice in {$weakest} will take {$his} results higher.",
                "Good progress, {$name}. ".ucfirst($he)." works hard in class; keep reading at home.",
            ],
            $percent >= 55 => [
                "{$name} is making steady progress. Regular revision of {$weakest} will help.",
                "{$name} can do better with more attention in class, especially in {$weakest}.",
            ],
            default => [
                "{$name} needs more practice, especially in {$weakest}. Please make sure homework is done daily.",
                "{$name} is finding {$weakest} difficult. Let's meet at the parent–teacher meeting to make a plan.",
            ],
        };

        return $this->dice->pick($options);
    }
}
