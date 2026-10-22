<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A subject allocation: who teaches which subject to which class (and
 * optionally section) in an academic year. Table: subject_class.
 */
class SubjectClass extends TenantModel
{
    use SoftDeletes;

    protected $table = 'subject_class';

    protected function casts(): array
    {
        return [
            'periods_per_week' => 'integer',
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
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<StaffProfile, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'teacher_id');
    }

    /**
     * @return HasMany<Timetable, $this>
     */
    public function timetables(): HasMany
    {
        return $this->hasMany(Timetable::class);
    }

    /**
     * @return HasMany<Homework, $this>
     */
    public function homeworks(): HasMany
    {
        return $this->hasMany(Homework::class);
    }
}
