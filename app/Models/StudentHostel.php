<?php

namespace App\Models;

use App\Enums\AllocationStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student staying in a hostel room (and bed) from check-in until they
 * check out.
 */
class StudentHostel extends TenantModel
{
    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'status' => AllocationStatus::class,
        ];
    }

    /**
     * @param  Builder<StudentHostel>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), AllocationStatus::Active);
    }

    /**
     * Stays that cover any day of the month starting $monthStart.
     *
     * @param  Builder<StudentHostel>  $query
     */
    public function scopeDuringMonth(Builder $query, CarbonInterface $monthStart): void
    {
        $query->whereDate($query->qualifyColumn('check_in'), '<=', $monthStart->copy()->endOfMonth()->toDateString())
            ->where(fn (Builder $query) => $query->whereNull($query->qualifyColumn('check_out'))
                ->orWhereDate($query->qualifyColumn('check_out'), '>=', $monthStart->copy()->startOfMonth()->toDateString()));
    }

    /**
     * @return BelongsTo<StudentProfile, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    /**
     * @return BelongsTo<HostelRoom, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(HostelRoom::class, 'hostel_room_id');
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function isActive(): bool
    {
        return $this->status === AllocationStatus::Active;
    }
}
