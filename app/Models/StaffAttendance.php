<?php

namespace App\Models;

use App\Enums\StaffAttendanceStatus;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAttendance extends TenantModel
{
    use AuditsChanges;

    /** Taking a register isn't logged (the register logs one summary); corrections are. */
    protected static $recordEvents = ['updated', 'deleted'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => StaffAttendanceStatus::class,
        ];
    }

    /**
     * @return BelongsTo<StaffProfile, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }

    /**
     * @return BelongsTo<LeaveRequest, $this>
     */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
