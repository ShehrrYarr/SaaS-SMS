<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mark extends TenantModel
{
    use AuditsChanges;

    /** Entering a sheet isn't logged per mark (the gradebook logs one summary); corrections are. */
    protected static $recordEvents = ['updated', 'deleted'];

    protected function casts(): array
    {
        return [
            'marks_obtained' => 'decimal:2',
            'is_absent' => 'boolean',
            'grade_point' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<ExamSchedule, $this>
     */
    public function examSchedule(): BelongsTo
    {
        return $this->belongsTo(ExamSchedule::class);
    }

    /**
     * @return BelongsTo<StudentProfile, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
