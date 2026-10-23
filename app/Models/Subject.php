<?php

namespace App\Models;

use App\Enums\SubjectType;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subject extends TenantModel
{
    use AuditsChanges, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => SubjectType::class,
        ];
    }

    /**
     * @return HasMany<SubjectClass, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(SubjectClass::class);
    }
}
