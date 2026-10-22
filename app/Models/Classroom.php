<?php

namespace App\Models;

use App\Enums\ClassroomType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Classroom extends TenantModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'type' => ClassroomType::class,
        ];
    }

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }
}
