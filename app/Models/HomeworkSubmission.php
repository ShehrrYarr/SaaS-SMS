<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's work on a homework. A row exists once the student hands
 * something in, or once the teacher grades work done on paper.
 *
 * Submitted / Late → Graded (marks and feedback), or Returned for another
 * attempt, which the student hands in again.
 */
class HomeworkSubmission extends TenantModel
{
    use AuditsChanges;

    /** Grading is logged with its context by the homework service. */
    protected static $recordEvents = ['deleted'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'status' => SubmissionStatus::class,
            'marks' => 'decimal:2',
            'graded_at' => 'datetime',
        ];
    }

    public function isHandedIn(): bool
    {
        return in_array($this->status, [SubmissionStatus::Submitted, SubmissionStatus::Late], true);
    }

    public function isGraded(): bool
    {
        return $this->status === SubmissionStatus::Graded;
    }

    public function attachmentName(): ?string
    {
        return $this->attachment ? basename($this->attachment) : null;
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
