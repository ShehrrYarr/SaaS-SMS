<?php

namespace App\Models;

use App\Enums\AllocationStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentHostel extends TenantModel
{
    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'status' => AllocationStatus::class,
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
     * @return BelongsTo<HostelRoom, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(HostelRoom::class, 'hostel_room_id');
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
