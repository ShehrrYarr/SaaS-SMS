<?php

namespace App\Models;

use App\Enums\ExamStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Examination extends TenantModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => ExamStatus::class,
            'results_published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * @return BelongsTo<GradeScale, $this>
     */
    public function gradeScale(): BelongsTo
    {
        return $this->belongsTo(GradeScale::class);
    }

    /**
     * @return HasMany<ExamSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(ExamSchedule::class);
    }

    public function isPublished(): bool
    {
        return $this->status === ExamStatus::Published;
    }
}
