<?php

namespace App\Models;

use App\Enums\AllocationStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student riding a route from a stop (pickup_point), from start_date until
 * the assignment is ended. The bus is the route's.
 */
class StudentTransport extends TenantModel
{
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => AllocationStatus::class,
        ];
    }

    /**
     * @param  Builder<StudentTransport>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), AllocationStatus::Active);
    }

    /**
     * Assignments that cover any day of the month starting $monthStart.
     *
     * @param  Builder<StudentTransport>  $query
     */
    public function scopeDuringMonth(Builder $query, CarbonInterface $monthStart): void
    {
        $query->whereDate($query->qualifyColumn('start_date'), '<=', $monthStart->copy()->endOfMonth()->toDateString())
            ->where(fn (Builder $query) => $query->whereNull($query->qualifyColumn('end_date'))
                ->orWhereDate($query->qualifyColumn('end_date'), '>=', $monthStart->copy()->startOfMonth()->toDateString()));
    }

    /**
     * @return BelongsTo<StudentProfile, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    /**
     * @return BelongsTo<TransportRoute, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'transport_route_id');
    }

    /**
     * @return BelongsTo<TransportVehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(TransportVehicle::class, 'transport_vehicle_id');
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
