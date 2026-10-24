<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\HostelType;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A hostel block for boys, girls or both, with its warden.
 */
class HostelBuilding extends TenantModel
{
    use AuditsChanges, SoftDeletes;

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

    /**
     * Whether a student of this gender may stay here (unknown gender always may).
     */
    public function admits(?Gender $gender): bool
    {
        return match ($this->type) {
            HostelType::Boys => $gender !== Gender::Female,
            HostelType::Girls => $gender !== Gender::Male,
            default => true,
        };
    }
}
