<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GradeScale extends TenantModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return HasMany<GradeScaleItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(GradeScaleItem::class)->orderByDesc('min_percentage');
    }

    /**
     * The band a percentage falls into, or null if the scale has a gap.
     */
    /**
     * The grade whose band starts at or below the percentage. Bands are
     * contiguous, so only their starting points matter (89.995% is an 80+
     * grade, not a gap between 89.99 and 90).
     */
    public function gradeFor(float $percentage): ?GradeScaleItem
    {
        return $this->items
            ->sortByDesc(fn (GradeScaleItem $item) => (float) $item->min_percentage)
            ->first(fn (GradeScaleItem $item) => $percentage >= (float) $item->min_percentage);
    }

    /**
     * @return HasMany<Examination, $this>
     */
    public function examinations(): HasMany
    {
        return $this->hasMany(Examination::class);
    }
}
