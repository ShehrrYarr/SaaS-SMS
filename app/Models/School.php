<?php

namespace App\Models;

use App\Enums\AcademicPeriodStatus;
use App\Enums\SchoolStatus;
use App\Enums\StudentStatus;
use App\Enums\SubscriptionState;
use App\Enums\UserType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * The tenant. Platform-level: not itself tenant-scoped.
 *
 * Subscription dates are inclusive calendar dates: a school whose
 * subscription_ends_at is 30 June can be used all day on 30 June.
 * subscription_ends_at is kept in step with the latest recorded payment
 * (see App\Services\Billing\SubscriptionManager).
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
            'trial_ends_at' => 'date',
            'subscription_ends_at' => 'date',
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
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function administrators(): HasMany
    {
        return $this->users()->where('user_type', UserType::SchoolAdmin);
    }

    /**
     * @return HasMany<StudentProfile, $this>
     */
    public function students(): HasMany
    {
        return $this->hasMany(StudentProfile::class);
    }

    /**
     * Students that count towards the plan limit.
     *
     * @return HasMany<StudentProfile, $this>
     */
    public function activeStudents(): HasMany
    {
        return $this->students()->where('status', StudentStatus::Active);
    }

    /**
     * @return HasMany<StaffProfile, $this>
     */
    public function staff(): HasMany
    {
        return $this->hasMany(StaffProfile::class);
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

    public function logoUrl(): ?string
    {
        return $this->logo ? Storage::disk('public')->url($this->logo) : null;
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
     * Address of the school's sign-in page.
     */
    public function loginUrl(): string
    {
        return route('tenant.login', ['school' => $this->slug]);
    }

    /**
     * The last day the school may be used without paying again (trial or paid
     * period, whichever ends later). Null when it has no end date.
     */
    public function accessEndsAt(): ?Carbon
    {
        $dates = array_filter([$this->trial_ends_at, $this->subscription_ends_at]);

        return $dates === [] ? null : max($dates)->copy();
    }

    /**
     * The day the grace period ends, after which the school is suspended.
     */
    public function graceEndsAt(): ?Carbon
    {
        return $this->accessEndsAt()?->addDays((int) config('plans.subscriptions.grace_days'));
    }

    public function subscriptionState(): SubscriptionState
    {
        $today = today();
        $endsAt = $this->accessEndsAt();

        return match (true) {
            $endsAt === null => SubscriptionState::Open,
            $this->subscription_ends_at?->gte($today) === true => SubscriptionState::Active,
            $this->trial_ends_at?->gte($today) === true => SubscriptionState::Trial,
            $this->graceEndsAt()->gte($today) => SubscriptionState::Grace,
            default => SubscriptionState::Expired,
        };
    }

    /**
     * The same rules as subscriptionState(), in SQL, for filters and counts.
     *
     * @param  Builder<School>  $query
     */
    public function scopeWhereSubscriptionState(Builder $query, SubscriptionState $state): void
    {
        $today = today()->toDateString();
        $graceCutoff = today()->subDays((int) config('plans.subscriptions.grace_days'))->toDateString();
        $endsAt = "GREATEST(COALESCE(DATE(trial_ends_at), '1000-01-01'), COALESCE(DATE(subscription_ends_at), '1000-01-01'))";
        $hasEndDate = fn (Builder $query) => $query->whereNotNull('trial_ends_at')->orWhereNotNull('subscription_ends_at');

        match ($state) {
            SubscriptionState::Open => $query->whereNull('trial_ends_at')->whereNull('subscription_ends_at'),
            SubscriptionState::Active => $query->whereDate('subscription_ends_at', '>=', $today),
            SubscriptionState::Trial => $query->whereDate('trial_ends_at', '>=', $today)
                ->where(fn (Builder $query) => $query->whereNull('subscription_ends_at')->orWhereDate('subscription_ends_at', '<', $today)),
            SubscriptionState::Grace => $query->where($hasEndDate)
                ->whereRaw("{$endsAt} < ?", [$today])
                ->whereRaw("{$endsAt} >= ?", [$graceCutoff]),
            SubscriptionState::Expired => $query->where($hasEndDate)->whereRaw("{$endsAt} < ?", [$graceCutoff]),
        };
    }

    /**
     * Schools whose access ends within the next $days days (and hasn't ended).
     *
     * @param  Builder<School>  $query
     */
    public function scopeExpiringWithin(Builder $query, int $days): void
    {
        $endsAt = "GREATEST(COALESCE(DATE(trial_ends_at), '1000-01-01'), COALESCE(DATE(subscription_ends_at), '1000-01-01'))";

        $query->where(fn (Builder $query) => $query->whereNotNull('trial_ends_at')->orWhereNotNull('subscription_ends_at'))
            ->whereRaw("{$endsAt} >= ?", [today()->toDateString()])
            ->whereRaw("{$endsAt} <= ?", [today()->addDays($days)->toDateString()]);
    }

    /**
     * Whole days left until access ends (negative once it has ended), or
     * null when there is no end date.
     */
    public function daysUntilAccessEnds(): ?int
    {
        $endsAt = $this->accessEndsAt();

        return $endsAt === null ? null : (int) today()->diffInDays($endsAt, false);
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
