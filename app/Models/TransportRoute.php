<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransportRoute extends TenantModel
{
    use SoftDeletes;

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
}
