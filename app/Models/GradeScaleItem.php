<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One band of a grade scale, e.g. A+ = 90–100%, 4.00 points, "Outstanding".
 */
class GradeScaleItem extends TenantModel
{
    protected function casts(): array
    {
        return [
            'min_percentage' => 'decimal:2',
            'max_percentage' => 'decimal:2',
            'grade_point' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<GradeScale, $this>
     */
    public function gradeScale(): BelongsTo
    {
        return $this->belongsTo(GradeScale::class);
    }
}
