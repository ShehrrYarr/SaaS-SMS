<?php

namespace App\Models;

use App\Enums\AcademicPeriodStatus;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class AcademicYear extends TenantModel
{
    use AuditsChanges, SoftDeletes;

    protected static function booted(): void
    {
        // Terms belong to their year: soft-delete them with it.
        static::deleting(function (AcademicYear $year) {
            if (! $year->isForceDeleting()) {
                $year->terms()->each(fn (Term $term) => $term->delete());
            }
        });
    }

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

    public function isCurrent(): bool
    {
        return $this->status === AcademicPeriodStatus::Current;
    }

    /**
     * Whether records (enrollments, exams) depend on this year.
     */
    public function isInUse(): bool
    {
        return $this->enrollments()->exists() || $this->examinations()->exists();
    }

    /**
     * Make this the school's only current year; the previous current year is archived.
     */
    public function makeCurrent(): void
    {
        DB::transaction(function () {
            static::query()
                ->whereKeyNot($this->getKey())
                ->where('status', AcademicPeriodStatus::Current)
                ->update(['status' => AcademicPeriodStatus::Archived]);

            $this->update(['status' => AcademicPeriodStatus::Current]);
        });
    }
}
