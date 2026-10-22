<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Section extends TenantModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return BelongsTo<Classroom, $this>
     */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /**
     * @return BelongsTo<StaffProfile, $this>
     */
    public function classTeacher(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'class_teacher_id');
    }

    /**
     * @return HasMany<StudentProfile, $this>
     */
    public function students(): HasMany
    {
        return $this->hasMany(StudentProfile::class);
    }
}
