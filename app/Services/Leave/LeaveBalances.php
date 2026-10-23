<?php

namespace App\Services\Leave;

use App\Enums\LeaveAppliesTo;
use App\Enums\LeaveStatus;
use App\Enums\UserStatus;
use App\Models\AcademicYear;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\StaffProfile;
use Illuminate\Support\Collection;

/**
 * Staff leave quotas per academic year. A leave type with "days a year"
 * gives every staff member that many days (HR can change one person's
 * allocation); leave counts against the year it starts in.
 *
 * "Used" is always recounted from approved requests, so it can't drift.
 */
class LeaveBalances
{
    /**
     * Active leave types with a quota that staff can take.
     *
     * @return Collection<int, LeaveType>
     */
    public function quotaTypes(): Collection
    {
        return LeaveType::query()->availableTo(LeaveAppliesTo::Staff)->whereNotNull('days_per_year')->get();
    }

    /**
     * Make sure every active staff member has a balance row for each quota
     * type in the year.
     */
    public function ensureAll(AcademicYear $year): void
    {
        $types = $this->quotaTypes();

        if ($types->isEmpty()) {
            return;
        }

        $existing = LeaveBalance::query()->where('academic_year_id', $year->id)->get(['staff_profile_id', 'leave_type_id'])
            ->map(fn (LeaveBalance $balance) => $balance->staff_profile_id.'-'.$balance->leave_type_id)
            ->flip();

        StaffProfile::query()
            ->whereHas('user', fn ($query) => $query->where('status', UserStatus::Active))
            ->select('id')
            ->chunkById(200, function ($staff) use ($types, $year, $existing) {
                foreach ($staff as $member) {
                    foreach ($types as $type) {
                        if (! $existing->has($member->id.'-'.$type->id)) {
                            $this->recalculate($member, $type, $year);
                        }
                    }
                }
            });
    }

    /**
     * The balance row, created with the type's allocation if missing, with
     * "used" recounted.
     */
    public function recalculate(StaffProfile $staff, LeaveType $type, AcademicYear $year): LeaveBalance
    {
        $balance = LeaveBalance::query()->firstOrNew([
            'staff_profile_id' => $staff->id,
            'leave_type_id' => $type->id,
            'academic_year_id' => $year->id,
        ]);

        if (! $balance->exists) {
            $balance->allocated = (float) ($type->days_per_year ?? 0);
        }

        $balance->used = $this->taken($staff, $type, $year, [LeaveStatus::Approved]);
        $balance->save();

        return $balance;
    }

    /**
     * Days still free to request: allocation less approved and pending
     * leave, or null when the type has no quota (or there's no year).
     */
    public function available(StaffProfile $staff, LeaveType $type, ?AcademicYear $year, ?int $exceptRequest = null): ?float
    {
        if ($type->days_per_year === null || $year === null) {
            return null;
        }

        $allocated = (float) (LeaveBalance::query()
            ->where('staff_profile_id', $staff->id)->where('leave_type_id', $type->id)->where('academic_year_id', $year->id)
            ->value('allocated') ?? $type->days_per_year);

        return $allocated - $this->taken($staff, $type, $year, [LeaveStatus::Approved, LeaveStatus::Pending], $exceptRequest);
    }

    /**
     * One person's leave for the year, every staff leave type: allocation
     * (null = no limit), used, pending and remaining.
     *
     * @return Collection<int, array{type: LeaveType, allocated: float|null, used: float, pending: float, remaining: float|null}>
     */
    public function summary(StaffProfile $staff, AcademicYear $year): Collection
    {
        $balances = LeaveBalance::query()->where('staff_profile_id', $staff->id)->where('academic_year_id', $year->id)->get()->keyBy('leave_type_id');

        return LeaveType::query()->availableTo(LeaveAppliesTo::Staff)->get()->map(function (LeaveType $type) use ($staff, $year, $balances) {
            $allocated = $type->days_per_year === null ? null : (float) ($balances->get($type->id)?->allocated ?? $type->days_per_year);
            $used = $this->taken($staff, $type, $year, [LeaveStatus::Approved]);
            $pending = $this->taken($staff, $type, $year, [LeaveStatus::Pending]);

            return [
                'type' => $type,
                'allocated' => $allocated,
                'used' => $used,
                'pending' => $pending,
                'remaining' => $allocated === null ? null : $allocated - $used - $pending,
            ];
        });
    }

    /**
     * @param  list<LeaveStatus>  $statuses
     */
    private function taken(StaffProfile $staff, LeaveType $type, AcademicYear $year, array $statuses, ?int $except = null): float
    {
        return (float) LeaveRequest::query()
            ->where('applicant_type', $staff->getMorphClass())
            ->where('applicant_id', $staff->id)
            ->where('leave_type_id', $type->id)
            ->whereIn('status', $statuses)
            ->whereDate('start_date', '>=', $year->start_date)
            ->whereDate('start_date', '<=', $year->end_date)
            ->when($except, fn ($query) => $query->whereKeyNot($except))
            ->sum('days');
    }
}
