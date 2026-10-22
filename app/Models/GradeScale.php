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
    public function gradeFor(float $percentage): ?GradeScaleItem
    {
        return $this->items->first(
            fn (GradeScaleItem $item) => $percentage >= (float) $item->min_percentage
                && $percentage <= (float) $item->max_percentage,
        );
    }
}
