<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A class / grade (e.g. "Grade 10"). Named SchoolClass because `class` is a
 * PHP keyword; the table is `classes`.
 */
class SchoolClass extends TenantModel
{
    use AuditsChanges, SoftDeletes;

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

    /**
     * @return HasMany<StudentEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class, 'class_id');
    }

    /**
     * Lowest level first: the order classes are listed and promoted in.
     *
     * @param  Builder<SchoolClass>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByRaw('numeric_level is null')->orderBy('numeric_level')->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Has students or enrollment history, so it must not be deleted.
     */
    public function isInUse(): bool
    {
        return $this->students()->exists() || $this->enrollments()->exists();
    }

    protected static function booted(): void
    {
        // Soft-deleting a class takes its sections and subject allocations with it.
        static::deleting(function (SchoolClass $class) {
            if (! $class->isForceDeleting()) {
                $class->sections()->get()->each->delete();
                $class->subjectAllocations()->get()->each->delete();
            }
        });
    }
}
