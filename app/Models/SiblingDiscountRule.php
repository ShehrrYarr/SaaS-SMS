<?php

namespace App\Models;

use App\Enums\AdjustmentType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "The 2nd child gets 10% off": the highest rule whose child_position is at
 * or below a child's position applies.
 */
class SiblingDiscountRule extends TenantModel
{
    protected function casts(): array
    {
        return [
            'child_position' => 'integer',
            'type' => AdjustmentType::class,
            'value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<FeeStructure, $this>
     */
    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }
}
