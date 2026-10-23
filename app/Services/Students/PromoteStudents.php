<?php

namespace App\Services\Students;

use App\Enums\EnrollmentStatus;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Tables\ActionFailed;
use Illuminate\Support\Facades\DB;

/**
 * Year-end promotion: moves a class's students from one academic year into
 * the next.
 *
 * Each student's enrollment in the old year is closed (promoted, retained,
 * graduated or left) and, for those who stay, a new enrollment is opened in
 * the new year. Their current class (on the profile) follows the new
 * enrollment, and their roll number is cleared for the new class to assign.
 */
class PromoteStudents
{
    public const PROMOTE = 'promote';

    public const RETAIN = 'retain';

    public const GRADUATE = 'graduate';

    public const LEAVE = 'leave';

    /**
     * @return array<string, string>
     */
    public static function outcomes(): array
    {
        return [
            self::PROMOTE => __('Promote'),
            self::RETAIN => __('Repeat class'),
            self::GRADUATE => __('Graduate'),
            self::LEAVE => __('Leaving'),
        ];
    }

    /**
     * @param  array<int|string, string>  $decisions  enrollment id (in $from) => outcome
     * @return array{promote: int, retain: int, graduate: int, leave: int, skipped: int}
     *
     * @throws ActionFailed
     */
    public function handle(AcademicYear $from, AcademicYear $to, ?SchoolClass $toClass, ?Section $toSection, array $decisions, ?User $by = null): array
    {
        if ($from->is($to)) {
            throw new ActionFailed(__('Choose a different academic year to promote into.'));
        }

        if ($toSection && $toClass && $toSection->class_id !== $toClass->id) {
            throw new ActionFailed(__('That section isn\'t part of the chosen class.'));
        }

        $counts = [self::PROMOTE => 0, self::RETAIN => 0, self::GRADUATE => 0, self::LEAVE => 0, 'skipped' => 0];

        DB::transaction(function () use ($from, $to, $toClass, $toSection, $decisions, $by, &$counts) {
            $enrollments = StudentEnrollment::query()
                ->with(['student.user', 'section'])
                ->where('academic_year_id', $from->id)
                ->where('status', EnrollmentStatus::Active)
                ->whereKey(array_keys($decisions))
                ->get();

            $alreadyEnrolled = StudentEnrollment::query()
                ->where('academic_year_id', $to->id)
                ->whereIn('student_profile_id', $enrollments->pluck('student_profile_id'))
                ->pluck('student_profile_id')
                ->all();

            foreach ($enrollments as $enrollment) {
                $outcome = $decisions[$enrollment->id] ?? null;
                $student = $enrollment->student;

                if (! $student || ! array_key_exists((string) $outcome, self::outcomes()) || in_array($student->id, $alreadyEnrolled, true)) {
                    $counts['skipped']++;

                    continue;
                }

                if ($outcome === self::PROMOTE && ! $toClass) {
                    throw new ActionFailed(__('Choose the class to promote into.'));
                }

                $leftOn = $from->end_date;

                match ($outcome) {
                    self::PROMOTE => $this->moveOn($enrollment, EnrollmentStatus::Promoted, $leftOn, $to, $toClass->id, $toSection?->id),
                    // Same class next year; the same section if it still exists.
                    self::RETAIN => $this->moveOn($enrollment, EnrollmentStatus::Retained, $leftOn, $to, $enrollment->class_id, $enrollment->section?->id),
                    self::GRADUATE => $this->finish($enrollment, EnrollmentStatus::Graduated, StudentStatus::Graduated, $leftOn),
                    self::LEAVE => $this->finish($enrollment, EnrollmentStatus::Left, StudentStatus::Left, $leftOn),
                };

                $counts[$outcome]++;
            }

            activity('student_profile')->causedBy($by)
                ->withProperties([
                    'from_year' => $from->name,
                    'to_year' => $to->name,
                    'to_class' => $toClass?->name,
                    'to_section' => $toSection?->name,
                    ...array_filter($counts),
                ])
                ->log('Students promoted');
        });

        return $counts;
    }

    private function moveOn(StudentEnrollment $enrollment, EnrollmentStatus $closedAs, mixed $leftOn, AcademicYear $to, int $classId, ?int $sectionId): void
    {
        $enrollment->update(['status' => $closedAs, 'left_on' => $leftOn]);

        StudentEnrollment::query()->create([
            'student_profile_id' => $enrollment->student_profile_id,
            'academic_year_id' => $to->id,
            'class_id' => $classId,
            'section_id' => $sectionId,
            'status' => EnrollmentStatus::Active,
            'enrolled_on' => $to->start_date,
        ]);

        $enrollment->student->update(['class_id' => $classId, 'section_id' => $sectionId, 'roll_number' => null]);
    }

    private function finish(StudentEnrollment $enrollment, EnrollmentStatus $closedAs, StudentStatus $status, mixed $leftOn): void
    {
        $enrollment->update(['status' => $closedAs, 'left_on' => $leftOn]);

        $enrollment->student->update(['status' => $status]);
        $enrollment->student->user?->update(['status' => UserStatus::Inactive]);
    }
}
