<?php

namespace App\Models;

use App\Enums\AcademicPeriodStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicYear extends TenantModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => AcademicPeriodStatus::class,
        ];
    }

    /**
     * @return HasMany<Term, $this>
     */
    public function terms(): HasMany
    {
        return $this->hasMany(Term::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<StudentEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    /**
     * @return HasMany<Examination, $this>
     */
    public function examinations(): HasMany
    {
        return $this->hasMany(Examination::class);
    }

    public function scopeCurrent(Builder $query): void
    {
        $query->where('status', AcademicPeriodStatus::Current);
    }
}
