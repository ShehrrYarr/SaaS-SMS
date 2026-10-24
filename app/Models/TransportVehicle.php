<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A school bus or van and its driver. Each route it runs seats up to its
 * capacity.
 */
class TransportVehicle extends TenantModel
{
    use AuditsChanges, SoftDeletes;

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'status' => RecordStatus::class,
        ];
    }

    /**
     * @return HasMany<TransportRoute, $this>
     */
    public function routes(): HasMany
    {
        return $this->hasMany(TransportRoute::class);
    }

    /**
     * "LEB-1234 · Toyota Coaster".
     */
    public function label(): string
    {
        return $this->vehicle_number.($this->model ? ' · '.$this->model : '');
    }
}
