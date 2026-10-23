<?php

namespace App\Models;

use App\Enums\AcademicPeriodStatus;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A term (semester) inside an academic year. At most one term per year is
 * current.
 */
class Term extends TenantModel
{
    use AuditsChanges, SoftDeletes;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => AcademicPeriodStatus::class,
            'sort_order' => 'integer',
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
     * @return HasMany<Examination, $this>
     */
    public function examinations(): HasMany
    {
        return $this->hasMany(Examination::class);
    }

    /**
     * @param  Builder<Term>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('status', AcademicPeriodStatus::Current);
    }

    public function isCurrent(): bool
    {
        return $this->status === AcademicPeriodStatus::Current;
    }

    public function isInUse(): bool
    {
        return $this->examinations()->exists();
    }

    /**
     * Make this the year's only current term. A term that was current becomes
     * archived if it came before this one, or upcoming if it comes after.
     */
    public function makeCurrent(): void
    {
        DB::transaction(function () {
            $others = static::query()
                ->where('academic_year_id', $this->academic_year_id)
                ->whereKeyNot($this->getKey())
                ->current()
                ->get();

            foreach ($others as $term) {
                $term->update([
                    'status' => $term->start_date->lt($this->start_date) ? AcademicPeriodStatus::Archived : AcademicPeriodStatus::Upcoming,
                ]);
            }

            $this->update(['status' => AcademicPeriodStatus::Current]);
        });
    }
}
