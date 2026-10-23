<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\LeaveRequest;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\StudentAttendance;
use App\Models\StudentProfile;
use App\Models\SubjectClass;
use App\Models\User;
use App\Tables\ActionFailed;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Daily student attendance, taken per class section (or per class, for a
 * class without sections).
 *
 * Who can mark what:
 *  - attendance.mark: today's register of a section the teacher is class
 *    teacher of or teaches a subject in;
 *  - attendance.manage: any section, any past date (corrections).
 *
 * Students on approved leave are pre-set to "excused". Taking a register
 * only creates rows; changes to a saved register are recorded in the
 * activity log.
 */
class StudentAttendanceRegister
{
    public function __construct(private readonly SchoolCalendar $calendar) {}

    /**
     * "4-12" for class 4, section 12; "4-0" for a class without sections.
     */
    public static function key(int $classId, ?int $sectionId): string
    {
        return $classId.'-'.($sectionId ?? 0);
    }

    /**
     * Registers the user can open, in class order.
     *
     * @return Collection<string, array{key: string, class: SchoolClass, section: Section|null, label: string}>
     */
    public function groupsFor(User $user): Collection
    {
        if (! $user->canAny(['attendance.view', 'attendance.mark', 'attendance.manage'])) {
            return collect();
        }

        $assigned = $user->can('attendance.manage') ? null : $this->assignedKeys($user);

        $classes = SchoolClass::query()->ordered()->with(['sections' => fn ($query) => $query->ordered()])->get();
        $groups = collect();

        foreach ($classes as $class) {
            foreach ($class->sections->isEmpty() ? [null] : $class->sections as $section) {
                $key = self::key($class->id, $section?->id);

                if ($assigned === null || in_array($key, $assigned, true)) {
                    $groups->put($key, [
                        'key' => $key,
                        'class' => $class,
                        'section' => $section,
                        'label' => $class->name.($section ? ' · '.$section->name : ''),
                    ]);
                }
            }
        }

        return $groups;
    }

    /**
     * Find one of the user's registers by key.
     *
     * @return array{key: string, class: SchoolClass, section: Section|null, label: string}|null
     */
    public function group(User $user, ?string $key): ?array
    {
        return $key ? $this->groupsFor($user)->get($key) : null;
    }

    /**
     * Sections the user is class teacher of, or teaches a subject in this year.
     *
     * @return list<string>
     */
    public function assignedKeys(User $user): array
    {
        $staff = $user->staffProfile()->first();

        if (! $staff) {
            return [];
        }

        $classTeacher = Section::query()->where('class_teacher_id', $staff->id)->get(['id', 'class_id'])
            ->map(fn (Section $section) => self::key($section->class_id, $section->id));

        $year = AcademicYear::query()->current()->value('id');

        $teaching = SubjectClass::query()
            ->where('teacher_id', $staff->id)
            ->when($year, fn ($query) => $query->where('academic_year_id', $year))
            ->get(['class_id', 'section_id'])
            ->map(fn (SubjectClass $allocation) => self::key($allocation->class_id, $allocation->section_id));

        return $classTeacher->merge($teaching)->unique()->values()->all();
    }

    public function canMark(User $user, string $key, CarbonInterface $date): bool
    {
        if ($date->gt(tenant()->today())) {
            return false;
        }

        if ($user->can('attendance.manage')) {
            return true;
        }

        return $user->can('attendance.mark')
            && $date->isSameDay(tenant()->today())
            && in_array($key, $this->assignedKeys($user), true);
    }

    /**
     * Active students placed in the class/section, by roll number then name.
     *
     * @return Collection<int, StudentProfile>
     */
    public function roster(SchoolClass $class, ?Section $section): Collection
    {
        return StudentProfile::query()
            ->with('user')
            ->where('class_id', $class->id)
            ->when($section, fn ($query) => $query->where('section_id', $section->id), fn ($query) => $query->whereNull('section_id'))
            ->where('status', StudentStatus::Active)
            ->get()
            ->sortBy(fn (StudentProfile $student) => [
                $student->roll_number === null ? 1 : 0,
                str_pad((string) $student->roll_number, 12, '0', STR_PAD_LEFT),
                mb_strtolower((string) $student->user?->name),
            ])
            ->values();
    }

    /**
     * Saved marks for a date, keyed by student.
     *
     * @param  Collection<int, StudentProfile>  $students
     * @return Collection<int, StudentAttendance>
     */
    public function saved(Collection $students, CarbonInterface $date): Collection
    {
        return StudentAttendance::query()
            ->whereIn('student_profile_id', $students->modelKeys())
            ->whereDate('date', $date)
            ->get()
            ->keyBy('student_profile_id');
    }

    /**
     * Approved leave covering the date, keyed by student.
     *
     * @param  list<int>  $studentIds
     * @return Collection<int, LeaveRequest>
     */
    public function approvedLeave(array $studentIds, CarbonInterface $date): Collection
    {
        return LeaveRequest::query()
            ->with('leaveType')
            ->where('applicant_type', (new StudentProfile)->getMorphClass())
            ->whereIn('applicant_id', $studentIds)
            ->where('status', LeaveStatus::Approved)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->get()
            ->keyBy('applicant_id');
    }

    /**
     * The register as a form: student id => status, remarks and flags.
     *
     * @param  Collection<int, StudentProfile>  $students
     * @return array<int, array{status: string, remarks: string, saved: bool, on_leave: bool}>
     */
    public function entries(Collection $students, CarbonInterface $date): array
    {
        $saved = $this->saved($students, $date);
        $leave = $this->approvedLeave($students->modelKeys(), $date);
        $entries = [];

        foreach ($students as $student) {
            $record = $saved->get($student->id);

            $entries[$student->id] = [
                'status' => $record?->status->value ?? ($leave->has($student->id) ? AttendanceStatus::Excused->value : AttendanceStatus::Present->value),
                'remarks' => (string) ($record?->remarks ?? ($leave->has($student->id) && ! $record ? $leave->get($student->id)->leaveType?->name : '')),
                'saved' => $record !== null,
                'on_leave' => $leave->has($student->id),
            ];
        }

        return $entries;
    }

    /**
     * Save a register. Only students on the roster are recorded.
     *
     * @param  array<int|string, array{status?: string|null, remarks?: string|null}>  $entries
     * @return array<string, int> count per status
     *
     * @throws ActionFailed
     */
    public function save(SchoolClass $class, ?Section $section, CarbonInterface $date, array $entries, User $by): array
    {
        if ($date->gt(tenant()->today())) {
            throw new ActionFailed(__('Attendance can\'t be taken for a future date.'));
        }

        return DB::transaction(function () use ($class, $section, $date, $entries, $by) {
            $students = $this->roster($class, $section);
            $saved = $this->saved($students, $date);
            $leave = $this->approvedLeave($students->modelKeys(), $date);
            $yearId = $this->calendar->yearFor($date)?->id;
            $counts = array_fill_keys(AttendanceStatus::values(), 0);

            foreach ($students as $student) {
                $status = AttendanceStatus::tryFrom((string) ($entries[$student->id]['status'] ?? ''));

                if (! $status) {
                    continue;
                }

                $remarks = trim((string) ($entries[$student->id]['remarks'] ?? ''));
                $values = [
                    'class_id' => $class->id,
                    'section_id' => $section?->id,
                    'academic_year_id' => $yearId,
                    'status' => $status,
                    'remarks' => $remarks === '' ? null : mb_substr($remarks, 0, 255),
                    'leave_request_id' => $status === AttendanceStatus::Excused ? $leave->get($student->id)?->id : null,
                ];

                $record = $saved->get($student->id) ?? new StudentAttendance([
                    'student_profile_id' => $student->id,
                    'date' => $date->toDateString(),
                ]);

                $record->fill($values);

                // Re-saving an unchanged register doesn't touch the rows.
                if (! $record->exists || $record->isDirty()) {
                    $record->marked_by = $by->id;
                    $record->save();
                }

                $counts[$status->value]++;
            }

            activity('student_attendance')->causedBy($by)
                ->withProperties([
                    'class' => $class->name,
                    'section' => $section?->name,
                    'date' => $date->toDateString(),
                    ...$counts,
                ])
                ->log('Attendance register saved');

            return $counts;
        });
    }

    /**
     * Status counts per register for one date (the admin overview).
     *
     * @return Collection<string, array<string, int>> key => [status => count]
     */
    public function dailySummary(CarbonInterface $date): Collection
    {
        return StudentAttendance::query()
            ->whereDate('date', $date)
            ->select(['class_id', 'section_id', 'status', DB::raw('count(*) as total')])
            ->groupBy(['class_id', 'section_id', 'status'])
            ->toBase()
            ->get()
            ->groupBy(fn ($row) => self::key((int) $row->class_id, $row->section_id ? (int) $row->section_id : null))
            ->map(fn (Collection $rows) => $rows->mapWithKeys(fn ($row) => [$row->status => (int) $row->total])->all());
    }

    /**
     * Active students per register (the admin overview).
     *
     * @return Collection<string, int>
     */
    public function rosterSizes(): Collection
    {
        return StudentProfile::query()
            ->where('status', StudentStatus::Active)
            ->whereNotNull('class_id')
            ->select(['class_id', 'section_id', DB::raw('count(*) as total')])
            ->groupBy(['class_id', 'section_id'])
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [self::key((int) $row->class_id, $row->section_id ? (int) $row->section_id : null) => (int) $row->total]);
    }
}
