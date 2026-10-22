<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A class / grade (e.g. "Grade 10"). Named SchoolClass because `class` is a
 * PHP keyword; the table is `classes`.
 */
class SchoolClass extends TenantModel
{
    use SoftDeletes;

    protected $table = 'classes';

    protected function casts(): array
    {
        return [
            'numeric_level' => 'integer',
            'capacity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * @return HasMany<Section, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class, 'class_id');
    }

    /**
     * @return HasMany<StudentProfile, $this>
     */
    public function students(): HasMany
    {
        return $this->hasMany(StudentProfile::class, 'class_id');
    }

    /**
     * @return HasMany<SubjectClass, $this>
     */
    public function subjectAllocations(): HasMany
    {
        return $this->hasMany(SubjectClass::class, 'class_id');
    }

    /**
     * @return HasMany<FeeStructure, $this>
     */
    public function feeStructures(): HasMany
    {
        return $this->hasMany(FeeStructure::class, 'class_id');
    }
}
