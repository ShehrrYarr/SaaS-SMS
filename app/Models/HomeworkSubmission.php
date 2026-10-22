<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeworkSubmission extends TenantModel
{
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'status' => SubmissionStatus::class,
            'marks' => 'decimal:2',
            'graded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Homework, $this>
     */
    public function homework(): BelongsTo
    {
        return $this->belongsTo(Homework::class);
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
    public function gradedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }
}
