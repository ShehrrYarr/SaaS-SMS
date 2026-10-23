<?php

namespace App\Services\Exams;

use App\Enums\AttendanceStatus;
use App\Models\Examination;
use App\Models\ExamRemark;
use App\Models\ExamSchedule;
use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\StudentAttendance;
use App\Models\StudentEnrollment;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;

/**
 * Examination results: per-student totals, percentage, grade, pass/fail
 * and position, for a class or one section, and the report card of one
 * student.
 *
 * A student passes when every subject is marked and each one reaches its
 * passing marks (an absence fails the subject). Positions use standard
 * competition ranking (1, 2, 2, 4) over students with complete marks.
 */
class ResultSheet
{
    public function __construct(
        private readonly Grading $grading,
        private readonly Gradebook $gradebook,
    ) {}

    /**
     * @return Collection<int, ExamSchedule>
     */
    public function schedules(Examination $exam, SchoolClass $class): Collection
    {
        return ExamSchedule::query()
            ->with('subject')
            ->where('examination_id', $exam->id)
            ->where('class_id', $class->id)
            ->get()
            ->sortBy(fn (ExamSchedule $schedule) => [$schedule->exam_date?->timestamp ?? PHP_INT_MAX, $schedule->subject?->name])
            ->values();
    }

    /**
     * @return array{schedules: Collection<int, ExamSchedule>, rows: Collection<int, array<string, mixed>>}
     */
    public function forClass(Examination $exam, SchoolClass $class, ?Section $section = null): array
    {
        $schedules = $this->schedules($exam, $class);
        $roster = $this->gradebook->roster($exam, $class, $section);

        $marks = Mark::query()
            ->whereIn('exam_schedule_id', $schedules->modelKeys())
            ->whereIn('student_profile_id', $roster->pluck('student_profile_id'))
            ->get()
            ->groupBy('student_profile_id')
            ->map(fn (Collection $marks) => $marks->keyBy('exam_schedule_id'));

        $totalMax = (float) $schedules->sum(fn (ExamSchedule $schedule) => (float) $schedule->max_marks);

        $rows = $roster->map(function (StudentEnrollment $enrollment) use ($schedules, $marks, $totalMax, $exam) {
            $mine = $marks->get($enrollment->student_profile_id, collect());
            $obtained = 0.0;
            $entered = 0;
            $failed = [];

            foreach ($schedules as $schedule) {
                $mark = $mine->get($schedule->id);

                if (! $mark || (! $mark->is_absent && $mark->marks_obtained === null)) {
                    continue;
                }

                $entered++;
                $obtained += (float) ($mark->marks_obtained ?? 0);

                if ($mark->is_absent || (float) $mark->marks_obtained < (float) $schedule->passing_marks) {
                    $failed[] = $schedule->subject?->name;
                }
            }

            $complete = $schedules->isNotEmpty() && $entered === $schedules->count();
            $percentage = $entered ? Grading::percentage($obtained, $totalMax) : null;
            $grade = $complete ? $this->grading->gradeFor($exam, $percentage) : null;

            return [
                'enrollment' => $enrollment,
                'student' => $enrollment->student,
                'marks' => $mine,
                'obtained' => $obtained,
                'max' => $totalMax,
                'percentage' => $percentage,
                'grade' => $grade?->grade,
                'grade_point' => $grade?->grade_point !== null ? (float) $grade->grade_point : null,
                'grade_remark' => $grade?->remark,
                'entered' => $entered,
                'complete' => $complete,
                'failed' => $failed,
                'passed' => $complete ? $failed === [] : null,
                'rank' => null,
            ];
        });

        return ['schedules' => $schedules, 'rows' => $this->rank($rows)];
    }

    /**
     * Everything printed on a student's report card, or null when they had
     * no class in that examination's year.
     *
     * @return array<string, mixed>|null
     */
    public function forStudent(Examination $exam, StudentProfile $student): ?array
    {
        $enrollment = StudentEnrollment::query()
            ->with(['schoolClass', 'section.classTeacher.user'])
            ->where('student_profile_id', $student->id)
            ->where('academic_year_id', $exam->academic_year_id)
            ->first();

        if (! $enrollment || ! $enrollment->schoolClass) {
            return null;
        }

        $sectionSheet = $this->forClass($exam, $enrollment->schoolClass, $enrollment->section);
        $row = $sectionSheet['rows']->first(fn (array $row) => $row['student']?->id === $student->id);

        if (! $row) {
            return null;
        }

        $classRank = $enrollment->section
            ? $this->forClass($exam, $enrollment->schoolClass)['rows']->first(fn (array $row) => $row['student']?->id === $student->id)['rank'] ?? null
            : $row['rank'];

        $subjects = $sectionSheet['schedules']->map(function (ExamSchedule $schedule) use ($row) {
            $mark = $row['marks']->get($schedule->id);

            return [
                'subject' => $schedule->subject?->name,
                'code' => $schedule->subject?->code,
                'max' => (float) $schedule->max_marks,
                'passing' => (float) $schedule->passing_marks,
                'obtained' => $mark?->marks_obtained !== null ? (float) $mark->marks_obtained : null,
                'absent' => (bool) $mark?->is_absent,
                'grade' => $mark?->grade,
                'remarks' => $mark?->remarks,
                'passed' => $mark ? (! $mark->is_absent && (float) $mark->marks_obtained >= (float) $schedule->passing_marks) : null,
            ];
        });

        return [
            'exam' => $exam,
            'student' => $student,
            'enrollment' => $enrollment,
            'class' => $enrollment->schoolClass,
            'section' => $enrollment->section,
            'class_teacher' => $enrollment->section?->classTeacher?->user?->name,
            'subjects' => $subjects,
            'result' => $row,
            'section_size' => $sectionSheet['rows']->count(),
            'class_rank' => $classRank,
            'attendance' => $this->attendance($exam, $student),
            'remarks' => ExamRemark::query()->where('examination_id', $exam->id)->where('student_profile_id', $student->id)->value('remarks'),
        ];
    }

    /**
     * Days present (late counts as present) out of days marked, over the
     * exam's term, or its academic year up to the end of the exam. Excused
     * days are counted separately and left out of the rate.
     *
     * @return array{present: int, marked: int, excused: int, rate: float|null}
     */
    public function attendance(Examination $exam, StudentProfile $student): array
    {
        $exam->loadMissing(['term', 'academicYear']);
        $from = $exam->term?->start_date ?? $exam->academicYear?->start_date;
        $to = $exam->end_date;

        $counts = StudentAttendance::query()
            ->where('student_profile_id', $student->id)
            ->when($from, fn ($query) => $query->whereDate('date', '>=', $from))
            ->whereDate('date', '<=', $to)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->toBase()
            ->pluck('total', 'status');

        return [
            'present' => (int) ($counts[AttendanceStatus::Present->value] ?? 0) + (int) ($counts[AttendanceStatus::Late->value] ?? 0),
            'marked' => (int) $counts->sum(),
            'excused' => (int) ($counts[AttendanceStatus::Excused->value] ?? 0),
            'rate' => AttendanceStatus::rate($counts),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function rank(Collection $rows): Collection
    {
        $ranked = $rows->filter(fn (array $row) => $row['complete'])->sortByDesc('percentage')->values();
        $positions = [];
        $previous = null;
        $position = 0;

        foreach ($ranked as $index => $row) {
            if ($row['percentage'] !== $previous) {
                $position = $index + 1;
                $previous = $row['percentage'];
            }

            $positions[$row['student']->id] = $position;
        }

        return $rows->map(fn (array $row) => [...$row, 'rank' => $positions[$row['student']?->id] ?? null]);
    }
}
