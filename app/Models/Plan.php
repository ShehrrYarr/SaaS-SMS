<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A SaaS subscription plan (platform-level, not tenant-scoped).
 */
class Plan extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'billing_cycle' => BillingCycle::class,
            'max_students' => 'integer',
            'max_staff' => 'integer',
            'modules' => 'array',
            'trial_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<School, $this>
     */
    public function schools(): HasMany
    {
        return $this->hasMany(School::class);
    }

    public function allowsModule(string $module): bool
    {
        return $this->modules === null || in_array($module, $this->modules, true);
    }
}
