<?php

namespace App\Models;

use App\Enums\AllocationStatus;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A bus route: its stops in order (each with an optional pickup time), the
 * vehicle that runs it and the monthly fare.
 */
class TransportRoute extends TenantModel
{
    use AuditsChanges, SoftDeletes;

    protected function casts(): array
    {
        return [
            'stops' => 'array',
            'fare' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<TransportVehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(TransportVehicle::class, 'transport_vehicle_id');
    }

    /**
     * @return HasMany<StudentTransport, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(StudentTransport::class);
    }

    /**
     * @return HasMany<StudentTransport, $this>
     */
    public function activeAssignments(): HasMany
    {
        return $this->assignments()->where('status', AllocationStatus::Active);
    }

    /**
     * The stops in order.
     *
     * @return Collection<int, array{name: string, time: string|null}>
     */
    public function stopList(): Collection
    {
        return collect($this->stops ?? [])
            ->filter(fn ($stop) => is_array($stop) && filled($stop['name'] ?? null))
            ->map(fn (array $stop) => ['name' => (string) $stop['name'], 'time' => filled($stop['time'] ?? null) ? (string) $stop['time'] : null])
            ->values();
    }

    /**
     * @return array{name: string, time: string|null}|null
     */
    public function stop(?string $name): ?array
    {
        return $name === null ? null : $this->stopList()->first(fn (array $stop) => mb_strtolower($stop['name']) === mb_strtolower($name));
    }

    /**
     * Pickup time of a stop in the school's time format, or null.
     */
    public function pickupTime(?string $stop): ?string
    {
        $time = $this->stop($stop)['time'] ?? null;

        return $time ? Carbon::parse($time)->format(tenant()?->setting('time_format') ?? 'h:i A') : null;
    }

    /**
     * Seats on the vehicle, or null when no vehicle is set.
     */
    public function seats(): ?int
    {
        return $this->vehicle ? $this->vehicle->capacity : null;
    }

    /**
     * "Start → End", or null.
     */
    public function endpoints(): ?string
    {
        $points = array_filter([$this->start_point, $this->end_point]);

        return $points === [] ? null : implode(' → ', $points);
    }
}
