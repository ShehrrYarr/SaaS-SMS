<?php

namespace App\Services\Attendance;

use App\Enums\LeaveStatus;
use App\Enums\StaffAttendanceStatus;
use App\Enums\UserStatus;
use App\Models\LeaveRequest;
use App\Models\StaffAttendance;
use App\Models\StaffProfile;
use App\Models\User;
use App\Tables\ActionFailed;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The daily staff register kept by HR: status plus check-in and check-out
 * times. Staff on approved leave are pre-set to "on leave".
 */
class StaffAttendanceRegister
{
    /**
     * Staff with an active account, by name; optionally one department.
     *
     * @return Collection<int, StaffProfile>
     */
    public function roster(?int $departmentId = null): Collection
    {
        return StaffProfile::query()
            ->with(['user', 'department'])
            ->whereHas('user', fn ($query) => $query->where('status', UserStatus::Active))
            ->when($departmentId, fn ($query) => $query->where('department_id', $departmentId))
            ->get()
            ->sortBy(fn (StaffProfile $staff) => mb_strtolower((string) $staff->user?->name))
            ->values();
    }

    /**
     * @param  Collection<int, StaffProfile>  $staff
     * @return array<int, array{status: string, check_in: string, check_out: string, remarks: string, saved: bool, on_leave: bool}>
     */
    public function entries(Collection $staff, CarbonInterface $date): array
    {
        $saved = StaffAttendance::query()
            ->whereIn('staff_profile_id', $staff->modelKeys())
            ->whereDate('date', $date)
            ->get()
            ->keyBy('staff_profile_id');

        $leave = $this->approvedLeave($staff->modelKeys(), $date);
        $entries = [];

        foreach ($staff as $member) {
            $record = $saved->get($member->id);

            $entries[$member->id] = [
                'status' => $record?->status->value ?? ($leave->has($member->id) ? StaffAttendanceStatus::OnLeave->value : StaffAttendanceStatus::Present->value),
                'check_in' => $record?->check_in ? substr($record->check_in, 0, 5) : '',
                'check_out' => $record?->check_out ? substr($record->check_out, 0, 5) : '',
                'remarks' => (string) ($record?->remarks ?? ''),
                'saved' => $record !== null,
                'on_leave' => $leave->has($member->id),
            ];
        }

        return $entries;
    }

    /**
     * @param  list<int>  $staffIds
     * @return Collection<int, LeaveRequest>
     */
    public function approvedLeave(array $staffIds, CarbonInterface $date): Collection
    {
        return LeaveRequest::query()
            ->where('applicant_type', (new StaffProfile)->getMorphClass())
            ->whereIn('applicant_id', $staffIds)
            ->where('status', LeaveStatus::Approved)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->get()
            ->keyBy('applicant_id');
    }

    /**
     * @param  array<int|string, array{status?: string|null, check_in?: string|null, check_out?: string|null, remarks?: string|null}>  $entries
     * @return array<string, int> count per status
     *
     * @throws ActionFailed
     */
    public function save(CarbonInterface $date, array $entries, User $by, ?int $departmentId = null): array
    {
        if ($date->gt(tenant()->today())) {
            throw new ActionFailed(__('Attendance can\'t be taken for a future date.'));
        }

        return DB::transaction(function () use ($date, $entries, $by, $departmentId) {
            $staff = $this->roster($departmentId);
            $saved = StaffAttendance::query()->whereIn('staff_profile_id', $staff->modelKeys())->whereDate('date', $date)->get()->keyBy('staff_profile_id');
            $leave = $this->approvedLeave($staff->modelKeys(), $date);
            $counts = array_fill_keys(StaffAttendanceStatus::values(), 0);

            foreach ($staff as $member) {
                $entry = $entries[$member->id] ?? null;
                $status = StaffAttendanceStatus::tryFrom((string) ($entry['status'] ?? ''));

                if (! $status) {
                    continue;
                }

                $present = in_array($status, [StaffAttendanceStatus::Present, StaffAttendanceStatus::Late, StaffAttendanceStatus::HalfDay], true);
                $remarks = trim((string) ($entry['remarks'] ?? ''));

                $record = $saved->get($member->id) ?? new StaffAttendance([
                    'staff_profile_id' => $member->id,
                    'date' => $date->toDateString(),
                ]);

                $record->fill([
                    'status' => $status,
                    'check_in' => $present ? $this->time($entry['check_in'] ?? null) : null,
                    'check_out' => $present ? $this->time($entry['check_out'] ?? null) : null,
                    'remarks' => $remarks === '' ? null : mb_substr($remarks, 0, 255),
                    'leave_request_id' => $status === StaffAttendanceStatus::OnLeave ? $leave->get($member->id)?->id : null,
                ]);

                if (! $record->exists || $record->isDirty()) {
                    $record->marked_by = $by->id;
                    $record->save();
                }

                $counts[$status->value]++;
            }

            activity('staff_attendance')->causedBy($by)
                ->withProperties(['date' => $date->toDateString(), ...$counts])
                ->log('Staff register saved');

            return $counts;
        });
    }

    /**
     * "09:05" → "09:05:00"; anything else → null.
     */
    private function time(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value.':00' : null;
    }
}
