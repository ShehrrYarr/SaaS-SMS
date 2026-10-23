<?php

namespace App\Models;

use App\Enums\ClassroomType;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Classroom extends TenantModel
{
    use AuditsChanges, SoftDeletes;

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'type' => ClassroomType::class,
        ];
    }

    /**
     * "101 · Science Lab", or just the room number.
     */
    public function label(): string
    {
        return $this->room_number.($this->name ? ' · '.$this->name : '');
    }

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }
}
