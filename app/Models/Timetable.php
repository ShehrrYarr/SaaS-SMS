<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Timetable extends TenantModel
{
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'period_number' => 'integer',
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
     * @return BelongsTo<SubjectClass, $this>
     */
    public function subjectClass(): BelongsTo
    {
        return $this->belongsTo(SubjectClass::class);
    }

    /**
     * @return BelongsTo<Classroom, $this>
     */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }
}
