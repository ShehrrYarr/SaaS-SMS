<?php

namespace App\Services\Leave;

use App\Enums\LeaveAppliesTo;
use App\Enums\LeaveStatus;
use App\Enums\StaffAttendanceStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\StaffAttendance;
use App\Models\StaffProfile;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Attendance\SchoolCalendar;
use App\Support\Portal;
use App\Tables\ActionFailed;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Leave for staff. Staff apply for themselves (leave.apply) or HR records it
 * for them; anyone with leave.approve except the applicant decides.
 *
 * Leave counts school days only, or half a day for a single half-day. A
 * type with a yearly quota can't be overbooked: approved plus pending leave
 * must fit in the allocation. Approved days show as "on leave" in the staff
 * register; unpaid leave is deducted in payroll.
 */
class StaffLeave
{
    public function __construct(
        private readonly SchoolCalendar $calendar,
        private readonly LeaveBalances $balances,
    ) {}

    /**
     * @throws ActionFailed
     */
    public function apply(
        StaffProfile $staff,
        LeaveType $type,
        CarbonInterface $start,
        CarbonInterface $end,
        string $reason,
        User $by,
        ?string $attachment = null,
        bool $halfDay = false,
    ): LeaveRequest {
        if (! $type->is_active || ! in_array($type->applies_to, [LeaveAppliesTo::Staff, LeaveAppliesTo::Both], true)) {
            throw new ActionFailed(__('That leave type isn\'t available for staff.'));
        }

        if ($end->lt($start)) {
            throw new ActionFailed(__('The leave must end on or after the day it starts.'));
        }

        if ($halfDay && ! $start->isSameDay($end)) {
            throw new ActionFailed(__('A half day must start and end on the same day.'));
        }

        $days = $this->calendar->countSchoolDays($start, $end);

        if ($days === 0) {
            throw new ActionFailed(__('There are no working days in those dates.'));
        }

        $days = $halfDay ? 0.5 : (float) $days;

        $overlapping = $staff->leaveRequests()
            ->whereIn('status', [LeaveStatus::Pending, LeaveStatus::Approved])
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->exists();

        if ($overlapping) {
            throw new ActionFailed(__('There is already a leave request for some of those days.'));
        }

        $this->ensureFits($staff, $type, $start, $days);

        $request = $staff->leaveRequests()->create([
            'leave_type_id' => $type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days' => $days,
            'reason' => trim($reason),
            'attachment' => $attachment,
            'status' => LeaveStatus::Pending,
            'requested_by' => $by->id,
        ]);

        activity('leave_request')->performedOn($request)->causedBy($by)
            ->withProperties(['staff' => $staff->user?->name, 'type' => $type->name, 'from' => $request->start_date->toDateString(), 'to' => $request->end_date->toDateString(), 'days' => $days])
            ->log('Staff leave requested');

        $this->notifyReviewers($request, $staff, $by);

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
     * Withdraw a pending request: by the applicant, or by HR (leave.manage).
     *
     * @throws ActionFailed
     */
    public function cancel(LeaveRequest $request, User $by): void
    {
        if (! $request->isPending()) {
            throw new ActionFailed(__('Only pending requests can be cancelled.'));
        }

        $applicant = $request->applicant;

        if (! ($applicant instanceof StaffProfile && $applicant->user_id === $by->id) && ! $by->can('leave.manage')) {
            throw new ActionFailed(__('Only the applicant can cancel this request.'));
        }

        $request->update(['status' => LeaveStatus::Cancelled]);

        activity('leave_request')->performedOn($request)->causedBy($by)->log('Staff leave cancelled');
    }

    public function canReview(User $user, LeaveRequest $request): bool
    {
        return $user->can('leave.approve')
            && $request->applicant_type === (new StaffProfile)->getMorphClass()
            && StaffProfile::query()->whereKey($request->applicant_id)->where('user_id', '!=', $user->id)->exists();
    }

    /**
     * @throws ActionFailed
     */
    private function ensureFits(StaffProfile $staff, LeaveType $type, CarbonInterface $start, float $days, ?int $exceptRequest = null): void
    {
        $available = $this->balances->available($staff, $type, $this->calendar->yearFor($start), $exceptRequest);

        if ($available !== null && $days > $available + 0.001) {
            throw new ActionFailed(__('Only :days day(s) of :type are left this year.', [
                'days' => rtrim(rtrim(number_format(max($available, 0), 1, '.', ''), '0'), '.'),
                'type' => $type->name,
            ]));
        }
    }

    /**
     * @throws ActionFailed
     */
    private function review(LeaveRequest $request, LeaveStatus $decision, User $by, ?string $remarks): void
    {
        if (! $request->isPending()) {
            throw new ActionFailed(__('This request has already been :status.', ['status' => mb_strtolower($request->status->label())]));
        }

        $staff = $request->applicant;

        if (! $staff instanceof StaffProfile) {
            throw new ActionFailed(__('This isn\'t a staff leave request.'));
        }

        if ($staff->user_id === $by->id) {
            throw new ActionFailed(__('You can\'t decide your own leave request.'));
        }

        $type = $request->leaveType()->firstOrFail();

        if ($decision === LeaveStatus::Approved) {
            $this->ensureFits($staff, $type, $request->start_date, (float) $request->days, $request->id);
        }

        DB::transaction(function () use ($request, $decision, $by, $remarks, $staff, $type) {
            $request->update([
                'status' => $decision,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'review_remarks' => filled($remarks) ? trim($remarks) : null,
            ]);

            if ($decision === LeaveStatus::Approved) {
                // Days already marked absent in the register become leave.
                StaffAttendance::query()
                    ->where('staff_profile_id', $staff->id)
                    ->whereDate('date', '>=', $request->start_date)
                    ->whereDate('date', '<=', $request->end_date)
                    ->where('status', StaffAttendanceStatus::Absent)
                    ->get()
                    ->each(fn (StaffAttendance $record) => $record->update([
                        'status' => StaffAttendanceStatus::OnLeave,
                        'leave_request_id' => $request->id,
                    ]));

                if ($year = $this->calendar->yearFor($request->start_date)) {
                    if ($type->days_per_year !== null) {
                        $this->balances->recalculate($staff, $type, $year);
                    }
                }
            }

            activity('leave_request')->performedOn($request)->causedBy($by)
                ->withProperties(array_filter(['staff' => $staff->user?->name, 'remarks' => $remarks]))
                ->log($decision === LeaveStatus::Approved ? 'Staff leave approved' : 'Staff leave rejected');
        });

        $this->notifyApplicant($request, $staff, $by);
    }

    private function notifyReviewers(LeaveRequest $request, StaffProfile $staff, User $by): void
    {
        $format = tenant()->setting('date_format');
        $staffTypes = collect(UserType::cases())
            ->reject(fn (UserType $type) => in_array($type, [UserType::SuperAdmin, UserType::Student, UserType::Parent], true))
            ->map(fn (UserType $type) => $type->value);

        User::query()
            ->where('status', UserStatus::Active)
            ->whereIn('user_type', $staffTypes)
            ->whereKeyNot([$by->id, $staff->user_id])
            ->get()
            ->filter(fn (User $user) => $user->can('leave.approve') && $user->canAccessPortal('hr'))
            ->each(fn (User $user) => $user->notify(new SystemNotification(
                title: __('Leave request from :name', ['name' => $staff->user?->name]),
                message: __(':type · :from to :to · :days day(s)', [
                    'type' => $request->leaveType?->name,
                    'from' => $request->start_date->format($format),
                    'to' => $request->end_date->format($format),
                    'days' => (float) $request->days,
                ]),
                url: route('hr.leave.index'),
                icon: 'ri-calendar-close-line',
                color: 'warning',
            )));
    }

    private function notifyApplicant(LeaveRequest $request, StaffProfile $staff, User $by): void
    {
        $user = $staff->user;

        if (! $user || $user->is($by) || ! $user->isActive()) {
            return;
        }

        $approved = $request->status === LeaveStatus::Approved;
        $format = tenant()->setting('date_format');

        $user->notify(new SystemNotification(
            title: $approved ? __('Your leave was approved') : __('Your leave was not approved'),
            message: $request->review_remarks ?: __(':from to :to', ['from' => $request->start_date->format($format), 'to' => $request->end_date->format($format)]),
            url: Portal::selfServiceUrl($user, 'my-leave'),
            icon: $approved ? 'ri-checkbox-circle-line' : 'ri-close-circle-line',
            color: $approved ? 'success' : 'danger',
        ));
    }
}
