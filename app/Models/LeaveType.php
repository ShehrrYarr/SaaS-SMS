<?php

namespace App\Models;

use App\Enums\LeaveAppliesTo;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveType extends TenantModel
{
    use AuditsChanges, SoftDeletes;

    /**
     * Active types that students (or staff) can take.
     *
     * @param  Builder<LeaveType>  $query
     */
    public function scopeAvailableTo(Builder $query, LeaveAppliesTo $who): void
    {
        $query->where('is_active', true)
            ->whereIn('applies_to', [$who->value, LeaveAppliesTo::Both->value])
            ->orderBy('name');
    }

    protected function casts(): array
    {
        return [
            'applies_to' => LeaveAppliesTo::class,
            'days_per_year' => 'integer',
            'is_paid' => 'boolean',
            'requires_attachment' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<LeaveRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
