<?php

namespace App\Models;

use App\Enums\HostelType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HostelBuilding extends TenantModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => HostelType::class,
        ];
    }

    /**
     * @return BelongsTo<StaffProfile, $this>
     */
    public function warden(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'warden_staff_profile_id');
    }

    /**
     * @return HasMany<HostelRoom, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(HostelRoom::class);
    }
}
