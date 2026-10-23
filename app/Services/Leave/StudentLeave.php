<?php

namespace App\Services\Leave;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveAppliesTo;
use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\ParentProfile;
use App\Models\Section;
use App\Models\StudentAttendance;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Attendance\SchoolCalendar;
use App\Tables\ActionFailed;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Leave for students. A parent applies (or staff record it for them); the
 * class teacher of the student's section, or anyone who manages attendance,
 * approves or rejects it. Approved days show as "excused" in the register.
 */
class StudentLeave
{
    public function __construct(private readonly SchoolCalendar $calendar) {}

    /**
     * @throws ActionFailed
     */
    public function apply(StudentProfile $student, LeaveType $type, CarbonInterface $start, CarbonInterface $end, string $reason, User $by, ?string $attachment = null): LeaveRequest
    {
        if (! $type->is_active || ! in_array($type->applies_to, [LeaveAppliesTo::Student, LeaveAppliesTo::Both], true)) {
            throw new ActionFailed(__('That leave type isn\'t available for students.'));
        }

        if ($end->lt($start)) {
            throw new ActionFailed(__('The leave must end on or after the day it starts.'));
        }

        $days = $this->calendar->countSchoolDays($start, $end);

        if ($days === 0) {
            throw new ActionFailed(__('There are no school days in those dates.'));
        }

        $overlapping = $student->leaveRequests()
            ->whereIn('status', [LeaveStatus::Pending, LeaveStatus::Approved])
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->exists();

        if ($overlapping) {
            throw new ActionFailed(__('There is already a leave request for some of those days.'));
        }

        $request = DB::transaction(fn () => $student->leaveRequests()->create([
            'leave_type_id' => $type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days' => $days,
            'reason' => trim($reason),
            'attachment' => $attachment,
            'status' => LeaveStatus::Pending,
            'requested_by' => $by->id,
        ]));

        activity('leave_request')->performedOn($request)->causedBy($by)
            ->withProperties(['student' => $student->user?->name, 'from' => $request->start_date->toDateString(), 'to' => $request->end_date->toDateString()])
            ->log('Student leave requested');

        $this->notifyReviewers($request, $student, $by);

        return $request;
    }

    /**
     * @throws ActionFailed
     */
    public function approve(LeaveRequest $request, User $by, ?string $remarks = null): void
    {
        $this->review($request, LeaveStatus::Approved, $by, $remarks);
    }

    /**
     * @throws ActionFailed
     */
    public function reject(LeaveRequest $request, User $by, ?string $remarks = null): void
    {
        $this->review($request, LeaveStatus::Rejected, $by, $remarks);
    }

    /**
     * Withdraw a request that hasn't been decided yet.
     *
     * @throws ActionFailed
     */
    public function cancel(LeaveRequest $request, User $by): void
    {
        if (! $request->isPending()) {
            throw new ActionFailed(__('Only pending requests can be cancelled.'));
        }

        $request->update(['status' => LeaveStatus::Cancelled]);

        activity('leave_request')->performedOn($request)->causedBy($by)->log('Student leave cancelled');
    }

    /**
     * Whether the user may approve or reject this request: they hold
     * student-leave.approve and either manage attendance or are the class
     * teacher of the student's section.
     */
    public function canReview(User $user, LeaveRequest $request): bool
    {
        if (! $user->can('student-leave.approve')) {
            return false;
        }

        if ($user->can('attendance.manage')) {
            return true;
        }

        $student = $request->applicant;
        $staffId = $user->staffProfile()->value('id');

        return $student instanceof StudentProfile && $staffId && $student->section_id
            && Section::query()->whereKey($student->section_id)->where('class_teacher_id', $staffId)->exists();
    }

    /**
     * Limit a leave-request query to what the user may see in the teacher
     * portal: everything when they manage attendance, otherwise the students
     * of sections they are class teacher of.
     *
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopeForReviewer(Builder $query, User $user): void
    {
        $query->where('applicant_type', (new StudentProfile)->getMorphClass());

        if ($user->can('attendance.manage')) {
            return;
        }

        $staffId = $user->staffProfile()->value('id');
        $sections = $staffId ? Section::query()->where('class_teacher_id', $staffId)->pluck('id') : collect();

        $query->whereIn('applicant_id', StudentProfile::query()->whereIn('section_id', $sections)->select('id'));
    }

    /**
     * Requests a parent can see: those of their own children.
     *
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopeForParent(Builder $query, ParentProfile $parent): void
    {
        $query->where('applicant_type', (new StudentProfile)->getMorphClass())
            ->whereIn('applicant_id', $parent->children()->select('student_profiles.id'));
    }

    /**
     * @throws ActionFailed
     */
    private function review(LeaveRequest $request, LeaveStatus $decision, User $by, ?string $remarks): void
    {
        if (! $request->isPending()) {
            throw new ActionFailed(__('This request has already been :status.', ['status' => mb_strtolower($request->status->label())]));
        }

        DB::transaction(function () use ($request, $decision, $by, $remarks) {
            $request->update([
                'status' => $decision,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'review_remarks' => filled($remarks) ? trim($remarks) : null,
            ]);

            // Days already marked absent become excused.
            if ($decision === LeaveStatus::Approved) {
                StudentAttendance::query()
                    ->where('student_profile_id', $request->applicant_id)
                    ->whereDate('date', '>=', $request->start_date)
                    ->whereDate('date', '<=', $request->end_date)
                    ->where('status', AttendanceStatus::Absent)
                    ->get()
                    ->each(fn (StudentAttendance $record) => $record->update([
                        'status' => AttendanceStatus::Excused,
                        'leave_request_id' => $request->id,
                    ]));
            }

            activity('leave_request')->performedOn($request)->causedBy($by)
                ->withProperties(array_filter(['remarks' => $remarks]))
                ->log($decision === LeaveStatus::Approved ? 'Student leave approved' : 'Student leave rejected');
        });

        $this->notifyRequester($request, $by);
    }

    private function notifyReviewers(LeaveRequest $request, StudentProfile $student, User $by): void
    {
        $teacher = $student->section_id
            ? Section::query()->with('classTeacher.user')->find($student->section_id)?->classTeacher?->user
            : null;

        if ($teacher && ! $teacher->is($by) && $teacher->isActive()) {
            $teacher->notify(new SystemNotification(
                title: __('Leave request for :name', ['name' => $student->user?->name]),
                message: __(':from to :to · :days day(s)', [
                    'from' => $request->start_date->format(tenant()->setting('date_format')),
                    'to' => $request->end_date->format(tenant()->setting('date_format')),
                    'days' => (float) $request->days,
                ]),
                url: route('teacher.student-leave.index'),
                icon: 'ri-calendar-close-line',
                color: 'warning',
            ));
        }
    }

    private function notifyRequester(LeaveRequest $request, User $by): void
    {
        $requester = $request->requestedBy()->first();

        if (! $requester || $requester->is($by)) {
            return;
        }

        $approved = $request->status === LeaveStatus::Approved;
        $name = $request->applicant?->user?->name;

        $requester->notify(new SystemNotification(
            title: $approved ? __('Leave approved for :name', ['name' => $name]) : __('Leave not approved for :name', ['name' => $name]),
            message: $request->review_remarks ?: __(':from to :to', [
                'from' => $request->start_date->format(tenant()->setting('date_format')),
                'to' => $request->end_date->format(tenant()->setting('date_format')),
            ]),
            url: $requester->parentProfile()->exists() ? route('parent.leave.index') : route('teacher.student-leave.index'),
            icon: $approved ? 'ri-checkbox-circle-line' : 'ri-close-circle-line',
            color: $approved ? 'success' : 'danger',
        ));
    }
}
