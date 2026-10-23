<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Support\Billing\Money;
use App\Support\Billing\PlanFeatures;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A SaaS subscription plan (platform-level, not tenant-scoped).
 *
 * `features` lists the optional features of config/plans.php the plan
 * includes; null means all of them. Null limits mean unlimited.
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
            'features' => 'array',
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

    /**
     * @param  Builder<Plan>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<Plan>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('price');
    }

    public function includesFeature(string $feature): bool
    {
        return $this->features === null || in_array($feature, $this->features, true);
    }

    public function includesAllFeatures(): bool
    {
        return $this->features === null;
    }

    /**
     * Labels of the included features, in config order.
     *
     * @return list<string>
     */
    public function featureLabels(): array
    {
        return array_values(array_filter(
            PlanFeatures::options(),
            fn (string $key) => $this->includesFeature($key),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /**
     * Max students or staff, null for unlimited.
     *
     * @param  'students'|'staff'  $resource
     */
    public function limit(string $resource): ?int
    {
        return match ($resource) {
            'students' => $this->max_students,
            'staff' => $this->max_staff,
        };
    }

    public function formattedPrice(): string
    {
        return Money::platform($this->price);
    }
}
