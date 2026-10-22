<?php

namespace App\Models;

use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HostelRoom extends TenantModel
{
    use SoftDeletes;

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
}
