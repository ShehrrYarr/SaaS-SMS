<?php

namespace App\Models;

use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransportVehicle extends TenantModel
{
    use SoftDeletes;

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
}
