<?php

namespace Database\Seeders\Demo\Greenfield;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\StaffAttendanceStatus;
use App\Enums\UserStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Services\Attendance\StaffAttendanceRegister;
use App\Services\Attendance\StudentAttendanceRegister;
use App\Services\Fees\LateFees;
use App\Services\Leave\StaffLeave;
use App\Services\Leave\StudentLeave;
use App\Services\Library\Library;
use App\Tables\ActionFailed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Staff and student leave, the daily registers, and the nightly jobs (late
 * fees, library reminders) as the scheduler would have run them.
 *
 * Registers older than a week are written in bulk; the last week goes
 * through the register services, as teachers marking them would.
 */
trait RecordsAttendance
{
    /** Sections still to take today's register (the dashboards show them as pending). */
    private const UNMARKED_TODAY = ['PREP-B', 'G5-B'];

    /**
     * [staff, leave type, starts (school days from today), days (0.5 = half day),
     *  applied (school days before it starts), outcome, reason, review remarks]
     */
    private const STAFF_LEAVE = [
        ['fariha', 'Sick Leave', -38, 2, 0, 'approved', 'Fever and flu; the doctor advised rest.', null],
        ['naveed', 'Casual Leave', -30, 3, 6, 'approved', 'My brother\'s wedding in Gujranwala.', 'Approved. Mr Shahid will cover Grade 5-B.'],
        ['kiran', 'Unpaid Leave', -27, 2, 4, 'approved', 'Personal matter out of town.', null],
        ['hamza', 'Casual Leave', -22, 1, 3, 'rejected', 'Family function.', 'Computer practicals are scheduled that day. Please choose another date.'],
        ['sobia', 'Sick Leave', -18, 0.5, 0, 'approved', 'Doctor\'s appointment in the afternoon.', null],
        ['sana', 'Sick Leave', -14, 1, 0, 'approved', 'Migraine.', 'Get well soon.'],
        ['omar', 'Casual Leave', -11, 1, 5, 'approved', 'Bank and registry office work for a property transfer.', null],
        ['hina', 'Casual Leave', -8, 1, 4, 'approved', 'My son\'s school function.', null],
        ['mehwish', 'Casual Leave', -5, 1, 2, 'cancelled', 'Visiting family in Kasur.', null],
        ['shahid', 'Casual Leave', 3, 2, 4, 'pending', 'Attending a family wedding in Sialkot.', null],
        ['laila', 'Casual Leave', 6, 1, 6, 'pending', 'Renewing my CNIC at the NADRA office.', null],
    ];

    private const SICK_REASONS = [
        ':name has a high fever; the doctor advised rest.',
        ':name has a stomach infection and is resting at home.',
        ':name has a bad cough and cold.',
        ':name has an eye infection; the doctor advised staying home.',
        ':name sprained an ankle and can\'t walk to class.',
    ];

    private const FAMILY_REASONS = [
        'A death in the family; we have to travel to our village.',
        'Grandmother is in hospital and the family is with her.',
        'An urgent family matter out of town.',
    ];

    private function planLeave(): void
    {
        $types = LeaveType::query()->pluck('id', 'name');

        foreach (self::STAFF_LEAVE as [$key, $type, $from, $days, $notice, $outcome, $reason, $remarks]) {
            $start = $this->addSchoolDays($this->today, $from);
            $end = $this->addSchoolDays($start, max(1, (int) $days) - 1);

            if ($start->lte($this->yearStart)) {
                continue;
            }

            $applied = $notice === 0 ? $this->on($start, '07:30') : $this->on($this->addSchoolDays($start, -$notice), '14:15');

            if (! $this->happened($applied)) {
                // Leave still to come was asked for on the last working day.
                $applied = $this->on($this->schoolDay($this->today->subDay(), back: true), '14:15');
            }

            $reviewer = $key === 'hina' ? $this->admin : $this->people['hina'];

            $this->at($applied, function () use ($key, $types, $type, $start, $end, $days, $reason, $outcome, $remarks, $reviewer, $applied, $notice) {
                $request = app(StaffLeave::class)->apply($this->staff[$key], LeaveType::query()->findOrFail($types[$type]),
                    $this->date($start), $this->date($end), $reason, $this->people[$key], halfDay: $days === 0.5);

                $decided = $notice === 0 ? $applied->setTime(10, 20) : $this->on($this->addSchoolDays($applied, 1), '09:40');

                $this->at($decided, fn () => match ($outcome) {
                    'approved' => app(StaffLeave::class)->approve($request, $reviewer, $remarks),
                    'rejected' => app(StaffLeave::class)->reject($request, $reviewer, $remarks),
                    'cancelled' => app(StaffLeave::class)->cancel($request, $this->people[$key]),
                    default => null,
                });
            });
        }

        $this->planStudentLeave(LeaveType::query()->whereIn('name', ['Sick Leave', 'Family Emergency'])->get()->keyBy('name')->all());
    }

    /**
     * Parents asking for leave and class teachers deciding. Ayesha Khan has
     * one approved in the past and one waiting for Ms Sana.
     *
     * @param  array<string, LeaveType>  $types
     */
    private function planStudentLeave(array $types): void
    {
        $ayesha = $this->persona('ayesha.khan');
        $zain = $this->persona('zain.khan');

        $requests = [
            [$ayesha, 'Sick Leave', -25, 2, true, 'approved', 'Ayesha has a high fever; the doctor advised two days\' rest.'],
            [$zain, 'Sick Leave', -12, 1, true, 'approved', 'Zain has an upset stomach and is resting at home.'],
            [$ayesha, 'Family Emergency', 4, 2, false, 'pending', 'Her grandmother is unwell in Faisalabad and the family is travelling to see her.'],
        ];

        // Others across the school.
        $pool = array_values(array_filter($this->pupils, fn (array $pupil) => $pupil['family'] > 1));

        for ($i = 0, $count = $this->scaled(16, 3); $i < $count && $pool !== []; $i++) {
            $pupil = $this->dice->pick($pool);
            $sick = $this->dice->chance(75);
            $from = $i < 2 ? $this->dice->int(0, 1) : -$this->dice->int(3, 50);

            $requests[] = [
                $pupil['student'], $sick ? 'Sick Leave' : 'Family Emergency', $from, $this->dice->int(1, 3), $sick,
                $from >= 0 ? 'pending' : ($this->dice->chance(90) ? 'approved' : 'rejected'),
                $sick ? __($this->dice->pick(self::SICK_REASONS), ['name' => $pupil['first']]) : $this->dice->pick(self::FAMILY_REASONS),
            ];
        }

        foreach ($requests as [$student, $type, $from, $days, $sameDay, $outcome, $reason]) {
            if (! $student || ! isset($types[$type])) {
                continue;
            }

            $start = $this->addSchoolDays($this->today, $from);
            $end = $this->addSchoolDays($start, $days - 1);
            $applied = $sameDay ? $this->on($start, '07:20') : $this->on($start->subDays($this->dice->int(1, 3)), '20:30');

            if (! $this->happened($applied)) {
                $applied = $this->on($this->today->subDay(), '20:30');
            }

            if ($start->lte($this->yearStart) || $applied->lt($this->on($student->admission_date, '18:00'))) {
                continue;
            }

            $this->at($applied, function () use ($student, $types, $type, $start, $end, $reason, $outcome, $applied) {
                $parent = $this->parentOf($student->id);
                $teacherKey = self::CLASS_TEACHERS[$this->pupils[$student->id]['section']];

                if (! $parent || $student->refresh()->status->value !== 'active') {
                    return;
                }

                try {
                    $request = $this->as($parent, fn () => app(StudentLeave::class)->apply($student, $types[$type], $this->date($start), $this->date($end), $reason, $parent));
                } catch (ActionFailed) {
                    return;
                }

                $decided = $this->on($applied->isSameDay($start) ? $start : $this->addSchoolDays($applied, 1), '11:'.$this->dice->int(10, 50));

                $this->at($decided, fn () => match ($outcome) {
                    'approved' => app(StudentLeave::class)->approve($request, $this->people[$teacherKey]),
                    'rejected' => app(StudentLeave::class)->reject($request, $this->people[$teacherKey], 'Please send a medical certificate and apply again.'),
                    default => null,
                });
            });
        }
    }

    private function planAttendance(): void
    {
        $recent = $this->addSchoolDays($this->today, -4);

        foreach ($this->schoolDaysBetween($this->yearStart, $this->today) as $day) {
            $live = $day->gte($recent);

            $this->at($this->on($day, '08:45'), fn () => $this->markStudents($day, $live));
            $this->at($this->on($day, '09:05'), fn () => $this->markStaff($day, $live));
        }
    }

    /**
     * The nightly scheduler: library reminders every morning; overdue
     * invoices and late fees at 1 a.m. on the nights they can change (after
     * the 10th's due date, after the grace days, and last night). Payments
     * bring their own invoice up to date in between.
     */
    private function planNightlyJobs(): void
    {
        $grace = (int) self::SETTINGS['fees']['late_fee']['grace_days'];

        for ($day = $this->yearStart->addDay(); $day->lte($this->today); $day = $day->addDay()) {
            $this->at($this->on($day, '07:00'), fn () => app(Library::class)->sendReminders($this->date($day)));

            if (in_array($day->day, [11, 10 + $grace + 1], true) || $day->isSameDay($this->today)) {
                $this->at($this->on($day, '01:00'), fn () => app(LateFees::class)->applyOverdue($this->date($day)));
            }
        }
    }

    private function markStudents(CarbonImmutable $day, bool $live): void
    {
        $students = StudentProfile::query()
            ->active()
            ->whereNotNull('section_id')
            ->whereDate('admission_date', '<=', $day->toDateString())
            ->get(['id', 'class_id', 'section_id']);

        $leave = $this->leaveOn('student_profile', $students->modelKeys(), $day);
        $sectionKeys = array_flip(array_map(fn ($section) => $section->id, $this->sections));
        $rows = [];

        foreach ($students->groupBy('section_id') as $sectionId => $roster) {
            $key = $sectionKeys[$sectionId];

            if ($day->isSameDay($this->today) && in_array($key, self::UNMARKED_TODAY, true)) {
                continue;
            }

            $teacher = $this->people[self::CLASS_TEACHERS[$key]];
            $entries = [];

            foreach ($roster as $student) {
                $request = $leave->get($student->id);
                $pupil = $this->pupils[$student->id] ?? ['absence' => 0.02, 'late' => 0.025];

                [$status, $remarks] = match (true) {
                    $request?->status === LeaveStatus::Approved => [AttendanceStatus::Excused, $request->leaveType?->name],
                    $request !== null => [AttendanceStatus::Absent, null],
                    $this->dice->chance($pupil['absence'] * 100) => [AttendanceStatus::Absent, null],
                    $this->dice->chance($pupil['late'] * 100) => [AttendanceStatus::Late, $this->dice->chance(30) ? 'Came at '.sprintf('8:%02d', $this->dice->int(10, 35)) : null],
                    default => [AttendanceStatus::Present, null],
                };

                $entries[$student->id] = ['status' => $status->value, 'remarks' => $remarks, 'leave' => $status === AttendanceStatus::Excused ? $request?->id : null];
            }

            if ($live) {
                $this->as($teacher, fn () => app(StudentAttendanceRegister::class)->save($this->classes[strstr($key, '-', true)], $this->sections[$key], $this->date($day), $entries, $teacher));

                continue;
            }

            foreach ($roster as $student) {
                $rows[] = [
                    'school_id' => $this->school->id,
                    'student_profile_id' => $student->id,
                    'academic_year_id' => $this->year->id,
                    'class_id' => $student->class_id,
                    'section_id' => $student->section_id,
                    'date' => $day->toDateString(),
                    'status' => $entries[$student->id]['status'],
                    'remarks' => $entries[$student->id]['remarks'],
                    'leave_request_id' => $entries[$student->id]['leave'],
                    'marked_by' => $teacher->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('student_attendances')->insert($chunk);
        }
    }

    private function markStaff(CarbonImmutable $day, bool $live): void
    {
        $staff = StaffProfile::query()
            ->whereHas('user', fn ($query) => $query->where('status', UserStatus::Active))
            ->where(fn ($query) => $query->whereNull('joining_date')->orWhereDate('joining_date', '<=', $day->toDateString()))
            ->get(['id']);

        $leave = $this->leaveOn('staff_profile', $staff->modelKeys(), $day);
        $afternoon = $this->happened($this->on($day, '14:00'));
        $entries = [];

        foreach ($staff as $member) {
            $request = $leave->get($member->id);
            $halfDayLeave = $request?->status === LeaveStatus::Approved && (float) $request->days === 0.5;

            $status = match (true) {
                $request?->status === LeaveStatus::Approved && ! $halfDayLeave => StaffAttendanceStatus::OnLeave,
                $request !== null && ! $halfDayLeave => StaffAttendanceStatus::Absent,
                $this->dice->chance(1.5) => StaffAttendanceStatus::Absent,
                $this->dice->chance(1) => StaffAttendanceStatus::HalfDay,
                $this->dice->chance(4) => StaffAttendanceStatus::Late,
                default => StaffAttendanceStatus::Present,
            };

            $present = in_array($status, [StaffAttendanceStatus::Present, StaffAttendanceStatus::Late, StaffAttendanceStatus::HalfDay], true);
            $checkIn = $status === StaffAttendanceStatus::Late
                ? sprintf('08:%02d', $this->dice->int(10, 40))
                : sprintf('07:%02d', $this->dice->int(35, 58));
            $checkOut = match (true) {
                $status === StaffAttendanceStatus::HalfDay, $halfDayLeave => '11:30',
                default => sprintf('%02d:%02d', $this->dice->int(14, 15), $this->dice->pick([0, 5, 10, 15, 20, 30, 45])),
            };

            $entries[$member->id] = [
                'status' => $status->value,
                'check_in' => $present ? $checkIn : null,
                'check_out' => $present && ($afternoon || $halfDayLeave) ? $checkOut : null,
                'remarks' => $halfDayLeave ? 'Half day leave' : null,
                'leave' => $status === StaffAttendanceStatus::OnLeave ? $request?->id : null,
            ];
        }

        $hr = $this->people['hina'];

        if ($live) {
            $this->as($hr, fn () => app(StaffAttendanceRegister::class)->save($this->date($day), $entries, $hr));

            return;
        }

        $rows = [];

        foreach ($entries as $id => $entry) {
            $rows[] = [
                'school_id' => $this->school->id,
                'staff_profile_id' => $id,
                'date' => $day->toDateString(),
                'status' => $entry['status'],
                'check_in' => $entry['check_in'] ? $entry['check_in'].':00' : null,
                'check_out' => $entry['check_out'] ? $entry['check_out'].':00' : null,
                'remarks' => $entry['remarks'],
                'leave_request_id' => $entry['leave'],
                'marked_by' => $hr->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('staff_attendances')->insert($rows);
    }

    /**
     * Pending or approved leave covering a day, by applicant.
     *
     * @param  list<int>  $ids
     * @return Collection<int, LeaveRequest>
     */
    private function leaveOn(string $type, array $ids, CarbonImmutable $day): Collection
    {
        return LeaveRequest::query()
            ->with('leaveType')
            ->where('applicant_type', $type)
            ->whereIn('applicant_id', $ids)
            ->whereIn('status', [LeaveStatus::Pending, LeaveStatus::Approved])
            ->whereDate('start_date', '<=', $day->toDateString())
            ->whereDate('end_date', '>=', $day->toDateString())
            ->get()
            ->keyBy('applicant_id');
    }

    private function persona(string $username): ?StudentProfile
    {
        foreach ($this->pupils as $pupil) {
            if ($pupil['student']->user?->username === $username) {
                return $pupil['student'];
            }
        }

        return null;
    }
}
