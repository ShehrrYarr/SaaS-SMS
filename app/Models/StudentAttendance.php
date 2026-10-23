<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentAttendance extends TenantModel
{
    use AuditsChanges;

    /** Taking a register isn't logged (the register logs one summary); corrections are. */
    protected static $recordEvents = ['updated', 'deleted'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => AttendanceStatus::class,
        ];
    }

    /**
     * @return BelongsTo<StudentProfile, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
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
