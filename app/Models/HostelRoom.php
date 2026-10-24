<?php

namespace App\Models;

use App\Enums\AllocationStatus;
use App\Enums\RecordStatus;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A room with a number of beds (capacity) and a monthly fee.
 */
class HostelRoom extends TenantModel
{
    use AuditsChanges, SoftDeletes;

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'fee' => 'decimal:2',
            'status' => RecordStatus::class,
        ];
    }

    /**
     * @return BelongsTo<HostelBuilding, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(HostelBuilding::class, 'hostel_building_id');
    }

    /**
     * @return HasMany<StudentHostel, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(StudentHostel::class);
    }

    /**
     * @return HasMany<StudentHostel, $this>
     */
    public function activeAllocations(): HasMany
    {
        return $this->allocations()->where('status', AllocationStatus::Active);
    }

    public function isActive(): bool
    {
        return $this->status === RecordStatus::Active;
    }

    /**
     * "Room 12 · Iqbal House".
     */
    public function label(bool $withBuilding = true): string
    {
        $room = __('Room :number', ['number' => $this->room_number]);

        return $withBuilding && $this->building ? $room.' · '.$this->building->name : $room;
    }
}
