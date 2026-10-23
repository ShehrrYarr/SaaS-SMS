<?php

namespace App\Services\Exams;

use App\Enums\EnrollmentStatus;
use App\Enums\ExamStatus;
use App\Models\Examination;
use App\Models\ExamRemark;
use App\Models\ExamSchedule;
use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\SubjectClass;
use App\Models\User;
use App\Tables\ActionFailed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Marks entry. A "sheet" is one subject of an examination for one class
 * section (or the whole class when it has no sections).
 *
 *  - marks.edit: sheets of subjects the teacher is allocated for that
 *    section in the exam's academic year;
 *  - marks.manage: every sheet.
 *
 * Marks can be entered while the exam is scheduled, ongoing or completed;
 * drafts aren't ready and published results are locked.
 *
 * Class teachers (and marks managers) also write each student's overall
 * report-card remark.
 */
class Gradebook
{
    public function __construct(private readonly Grading $grading) {}

    public static function key(int $scheduleId, ?int $sectionId): string
    {
        return $scheduleId.'-'.($sectionId ?? 0);
    }

    public static function isOpen(Examination $exam): bool
    {
        return ! in_array($exam->status, [ExamStatus::Draft, ExamStatus::Published], true);
    }

    /**
     * Examinations the gradebook offers: open ones, plus published ones to
     * look back at.
     *
     * @return Collection<int, Examination>
     */
    public function exams(): Collection
    {
        return Examination::query()
            ->with('academicYear')
            ->where('status', '!=', ExamStatus::Draft)
            ->orderByDesc('start_date')
            ->get();
    }

    /**
     * Sheets the user may open for an exam, in class and subject order.
     *
     * @return Collection<string, array{key: string, schedule: ExamSchedule, class: SchoolClass, section: Section|null, label: string}>
     */
    public function sheetsFor(User $user, Examination $exam): Collection
    {
        if (! $user->canAny(['marks.view', 'marks.edit', 'marks.manage'])) {
            return collect();
        }

        $allocations = $user->can('marks.manage') ? null : $this->allocationsOf($user, $exam);

        $schedules = ExamSchedule::query()
            ->with(['subject', 'schoolClass.sections' => fn ($query) => $query->ordered()])
            ->where('examination_id', $exam->id)
            ->get()
            ->filter(fn (ExamSchedule $schedule) => $schedule->schoolClass && $schedule->subject)
            ->sortBy(fn (ExamSchedule $schedule) => [$schedule->schoolClass->numeric_level ?? 999, $schedule->schoolClass->name, $schedule->subject->name]);

        $sheets = collect();

        foreach ($schedules as $schedule) {
            $class = $schedule->schoolClass;

            foreach ($class->sections->isEmpty() ? [null] : $class->sections as $section) {
                $allowed = $allocations === null || $allocations->contains(fn (SubjectClass $allocation) => $allocation->class_id === $class->id
                    && $allocation->subject_id === $schedule->subject_id
                    && ($allocation->section_id === null || $allocation->section_id === $section?->id));

                if ($allowed) {
                    $key = self::key($schedule->id, $section?->id);
                    $sheets->put($key, [
                        'key' => $key,
                        'schedule' => $schedule,
                        'class' => $class,
                        'section' => $section,
                        'label' => $class->name.($section ? ' · '.$section->name : '').' — '.$schedule->subject->name,
                    ]);
                }
            }
        }

        return $sheets;
    }

    /**
     * Sections whose overall remarks the user writes: their class-teacher
     * sections, or all with marks.manage.
     *
     * @return Collection<string, array{key: string, class: SchoolClass, section: Section|null, label: string}>
     */
    public function remarkGroupsFor(User $user, Examination $exam): Collection
    {
        $classIds = ExamSchedule::query()->where('examination_id', $exam->id)->distinct()->pluck('class_id');
        $classes = SchoolClass::query()->ordered()->with(['sections' => fn ($query) => $query->ordered()])->whereIn('id', $classIds)->get();

        $manage = $user->can('marks.manage');
        $staffId = $manage ? null : $user->staffProfile()->value('id');

        if (! $manage && ! ($staffId && $user->can('marks.edit'))) {
            return collect();
        }

        $groups = collect();

        foreach ($classes as $class) {
            foreach ($class->sections->isEmpty() ? [null] : $class->sections as $section) {
                if ($manage || ($section && $section->class_teacher_id === $staffId)) {
                    $key = 'r-'.$class->id.'-'.($section?->id ?? 0);
                    $groups->put($key, ['key' => $key, 'class' => $class, 'section' => $section, 'label' => $class->name.($section ? ' · '.$section->name : '')]);
                }
            }
        }

        return $groups;
    }

    public function canEdit(User $user, Examination $exam): bool
    {
        return self::isOpen($exam) && $user->canAny(['marks.edit', 'marks.manage']);
    }

    /**
     * Students enrolled in the class (and section) in the exam's academic
     * year, by roll number then name. Students who left are only listed if
     * they already have marks.
     *
     * @return Collection<int, StudentEnrollment>
     */
    public function roster(Examination $exam, SchoolClass $class, ?Section $section, ?ExamSchedule $schedule = null): Collection
    {
        return StudentEnrollment::query()
            ->with(['student.user', 'section'])
            ->where('academic_year_id', $exam->academic_year_id)
            ->where('class_id', $class->id)
            ->when($section, fn ($query) => $query->where('section_id', $section->id))
            ->whereHas('student')
            ->where(fn ($query) => $query
                ->whereNotIn('status', [EnrollmentStatus::Left, EnrollmentStatus::Transferred])
                ->when($schedule, fn ($query) => $query->orWhereHas('student.marks', fn ($marks) => $marks->where('exam_schedule_id', $schedule->id))))
            ->get()
            ->sortBy(fn (StudentEnrollment $enrollment) => [
                $enrollment->roll_number === null ? 1 : 0,
                str_pad((string) $enrollment->roll_number, 12, '0', STR_PAD_LEFT),
                mb_strtolower((string) $enrollment->student?->user?->name),
            ])
            ->values();
    }

    /**
     * The sheet as a form: student id => marks, absent, remarks.
     *
     * @param  Collection<int, StudentEnrollment>  $roster
     * @return array<int, array{marks: string, absent: bool, remarks: string}>
     */
    public function entries(ExamSchedule $schedule, Collection $roster): array
    {
        $marks = Mark::query()
            ->where('exam_schedule_id', $schedule->id)
            ->whereIn('student_profile_id', $roster->pluck('student_profile_id'))
            ->get()
            ->keyBy('student_profile_id');

        $entries = [];

        foreach ($roster as $enrollment) {
            $mark = $marks->get($enrollment->student_profile_id);

            $entries[$enrollment->student_profile_id] = [
                'marks' => $mark?->marks_obtained !== null ? self::trim($mark->marks_obtained) : '',
                'absent' => (bool) $mark?->is_absent,
                'remarks' => (string) $mark?->remarks,
            ];
        }

        return $entries;
    }

    /**
     * Save a sheet. A blank, not-absent row clears that student's mark.
     *
     * @param  array<int|string, array{marks?: string|float|null, absent?: bool, remarks?: string|null}>  $entries
     * @return int number of students with a mark or absence recorded
     *
     * @throws ActionFailed
     */
    public function save(Examination $exam, ExamSchedule $schedule, ?Section $section, array $entries, User $by): int
    {
        if (! self::isOpen($exam)) {
            throw new ActionFailed($exam->isPublished()
                ? __('Results are published, so marks are locked.')
                : __('This examination isn\'t open for marks yet.'));
        }

        $max = (float) $schedule->max_marks;

        return DB::transaction(function () use ($exam, $schedule, $section, $entries, $by, $max) {
            $roster = $this->roster($exam, $schedule->schoolClass, $section, $schedule);
            $existing = Mark::query()
                ->where('exam_schedule_id', $schedule->id)
                ->whereIn('student_profile_id', $roster->pluck('student_profile_id'))
                ->get()
                ->keyBy('student_profile_id');

            $recorded = 0;

            foreach ($roster as $enrollment) {
                $id = $enrollment->student_profile_id;
                $entry = $entries[$id] ?? null;

                if ($entry === null) {
                    continue;
                }

                $absent = (bool) ($entry['absent'] ?? false);
                $raw = trim((string) ($entry['marks'] ?? ''));
                $remarks = trim((string) ($entry['remarks'] ?? ''));
                $mark = $existing->get($id);

                if (! $absent && $raw === '') {
                    $mark?->delete();

                    continue;
                }

                $obtained = $absent ? null : (float) $raw;

                if (! $absent && (! is_numeric($raw) || $obtained < 0 || $obtained > $max)) {
                    throw new ActionFailed(__('Marks for :name must be between 0 and :max.', ['name' => $enrollment->student?->user?->name, 'max' => self::trim($max)]));
                }

                $mark ??= new Mark(['exam_schedule_id' => $schedule->id, 'student_profile_id' => $id]);
                $mark->fill([
                    'marks_obtained' => $obtained,
                    'is_absent' => $absent,
                    'remarks' => $remarks === '' ? null : mb_substr($remarks, 0, 255),
                    ...$this->grading->forMarks($exam, $obtained, $max, $absent),
                ]);

                if (! $mark->exists || $mark->isDirty()) {
                    $mark->entered_by = $by->id;
                    $mark->save();
                }

                $recorded++;
            }

            activity('mark')->causedBy($by)
                ->withProperties([
                    'examination' => $exam->name,
                    'class' => $schedule->schoolClass->name,
                    'section' => $section?->name,
                    'subject' => $schedule->subject?->name,
                    'recorded' => $recorded,
                ])
                ->log('Marks entered');

            return $recorded;
        });
    }

    /**
     * @return array<int, string> student id => remark
     */
    public function remarks(Examination $exam, Collection $roster): array
    {
        $saved = ExamRemark::query()
            ->where('examination_id', $exam->id)
            ->whereIn('student_profile_id', $roster->pluck('student_profile_id'))
            ->pluck('remarks', 'student_profile_id');

        return $roster->mapWithKeys(fn (StudentEnrollment $enrollment) => [
            $enrollment->student_profile_id => (string) ($saved[$enrollment->student_profile_id] ?? ''),
        ])->all();
    }

    /**
     * @param  array<int|string, string|null>  $remarks  student id => remark
     *
     * @throws ActionFailed
     */
    public function saveRemarks(Examination $exam, SchoolClass $class, ?Section $section, array $remarks, User $by): void
    {
        if ($exam->isPublished()) {
            throw new ActionFailed(__('Results are published, so remarks are locked.'));
        }

        DB::transaction(function () use ($exam, $class, $section, $remarks, $by) {
            foreach ($this->roster($exam, $class, $section) as $enrollment) {
                $text = trim((string) ($remarks[$enrollment->student_profile_id] ?? ''));
                $existing = ExamRemark::query()->where('examination_id', $exam->id)->where('student_profile_id', $enrollment->student_profile_id)->first();

                if ($text === '') {
                    $existing?->delete();

                    continue;
                }

                $existing
                    ? ($existing->remarks !== $text ? $existing->update(['remarks' => mb_substr($text, 0, 1000), 'entered_by' => $by->id]) : null)
                    : ExamRemark::query()->create([
                        'examination_id' => $exam->id,
                        'student_profile_id' => $enrollment->student_profile_id,
                        'remarks' => mb_substr($text, 0, 1000),
                        'entered_by' => $by->id,
                    ]);
            }
        });
    }

    /**
     * Subject allocations of the user's staff profile in the exam's year.
     *
     * @return Collection<int, SubjectClass>
     */
    private function allocationsOf(User $user, Examination $exam): Collection
    {
        $staffId = $user->staffProfile()->value('id');

        return $staffId
            ? SubjectClass::query()->where('teacher_id', $staffId)->where('academic_year_id', $exam->academic_year_id)->get(['class_id', 'section_id', 'subject_id'])
            : collect();
    }

    /**
     * "45.00" → "45", "45.50" → "45.5".
     */
    public static function trim(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
