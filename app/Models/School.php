<?php

namespace App\Models;

use App\Enums\AcademicPeriodStatus;
use App\Enums\SchoolStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The tenant. Platform-level: not itself tenant-scoped.
 */
class School extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SchoolStatus::class,
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<SubscriptionPayment, $this>
     */
    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /**
     * @return HasMany<Campus, $this>
     */
    public function campuses(): HasMany
    {
        return $this->hasMany(Campus::class);
    }

    /**
     * @return HasMany<AcademicYear, $this>
     */
    public function academicYears(): HasMany
    {
        return $this->hasMany(AcademicYear::class);
    }

    /**
     * @return HasOne<AcademicYear, $this>
     */
    public function currentAcademicYear(): HasOne
    {
        return $this->hasOne(AcademicYear::class)->where('status', AcademicPeriodStatus::Current);
    }

    public function isActive(): bool
    {
        return $this->status === SchoolStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === SchoolStatus::Suspended;
    }

    /**
     * A school setting, falling back to config('tenancy.default_settings').
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        $settings = array_replace_recursive(config('tenancy.default_settings'), $this->settings ?? []);

        return data_get($settings, $key, $default);
    }
}
