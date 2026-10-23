<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One period of a class/section's week: which allocation (subject and
 * teacher) is taught, on which day, in which room.
 */
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
     * Slots of one class/section; a class without sections has section null.
     *
     * @param  Builder<Timetable>  $query
     */
    public function scopeForGroup(Builder $query, int $classId, ?int $sectionId): void
    {
        $query->where($query->qualifyColumn('class_id'), $classId)
            ->when($sectionId, fn (Builder $query) => $query->where($query->qualifyColumn('section_id'), $sectionId), fn (Builder $query) => $query->whereNull($query->qualifyColumn('section_id')));
    }

    /**
     * "08:00 – 08:40" in the school's time format.
     */
    public function timeLabel(): string
    {
        $format = tenant()?->setting('time_format') ?? 'h:i A';

        return Carbon::parse($this->start_time)->format($format).' – '.Carbon::parse($this->end_time)->format($format);
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
        return $this->belongsTo(SubjectClass::class)->withTrashed();
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
     * @return BelongsTo<Classroom, $this>
     */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }
}
