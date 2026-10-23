<?php

namespace App\Models;

use App\Enums\NoticeAudience;
use App\Enums\NoticeState;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;

/**
 * A notice-board announcement. It is live between published_at and
 * expires_at, and reaches an audience (everyone, staff, teachers, students
 * or parents), optionally only the students or parents of one class.
 */
class Notice extends TenantModel
{
    use AuditsChanges {
        getActivitylogOptions as defaultActivitylogOptions;
    }
    use SoftDeletes;

    /** Disk for attachments: private, served through an authorised route. */
    public const DISK = 'local';

    protected function casts(): array
    {
        return [
            'target_audience' => NoticeAudience::class,
            'is_pinned' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        // The body can be long; the log only needs to know it changed.
        return $this->defaultActivitylogOptions()->logExcept(['created_at', 'updated_at', 'notified_at', 'body']);
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<Notice>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Pinned first, then newest.
     *
     * @param  Builder<Notice>  $query
     */
    public function scopeForBoard(Builder $query): void
    {
        $query->orderByDesc('is_pinned')->orderByDesc('published_at');
    }

    /**
     * Notices addressed to this user (whatever their publish state).
     *
     * @param  Builder<Notice>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $audiences = static::audiencesFor($user);

        $query->where(function (Builder $query) use ($user, $audiences) {
            $query->whereIn('target_audience', array_diff($audiences, [NoticeAudience::Students->value, NoticeAudience::Parents->value]));

            foreach ([NoticeAudience::Students, NoticeAudience::Parents] as $audience) {
                if (! in_array($audience->value, $audiences, true)) {
                    continue;
                }

                $classIds = static::classIdsFor($user, $audience);

                $query->orWhere(fn (Builder $query) => $query
                    ->where('target_audience', $audience)
                    ->where(fn (Builder $query) => $query->whereNull('class_id')->orWhereIn('class_id', $classIds)));
            }
        });
    }

    /**
     * @return list<string>
     */
    public static function audiencesFor(User $user): array
    {
        $audiences = [NoticeAudience::All->value];

        if ($user->user_type?->isStaff()) {
            $audiences[] = NoticeAudience::Staff->value;
        }

        $audiences[] = match ($user->user_type) {
            UserType::Teacher => NoticeAudience::Teachers->value,
            UserType::Student => NoticeAudience::Students->value,
            UserType::Parent => NoticeAudience::Parents->value,
            default => null,
        };

        return array_values(array_filter($audiences));
    }

    /**
     * Classes the user belongs to as a student, or their children's classes as a parent.
     *
     * @return list<int>
     */
    private static function classIdsFor(User $user, NoticeAudience $audience): array
    {
        return match ($audience) {
            NoticeAudience::Students => StudentProfile::query()->where('user_id', $user->id)->whereNotNull('class_id')->pluck('class_id')->all(),
            NoticeAudience::Parents => StudentProfile::query()
                ->whereHas('parents', fn (Builder $parents) => $parents->where($parents->qualifyColumn('user_id'), $user->id))
                ->whereNotNull('class_id')
                ->pluck('class_id')
                ->all(),
            default => [],
        };
    }

    /**
     * Active accounts the notice is addressed to.
     *
     * @return Builder<User>
     */
    public function recipients(): Builder
    {
        $query = User::query()->where('status', UserStatus::Active);
        $staffTypes = array_values(array_filter(UserType::cases(), fn (UserType $type) => $type->isStaff()));

        match ($this->target_audience) {
            NoticeAudience::All => null,
            NoticeAudience::Staff => $query->whereIn('user_type', $staffTypes),
            NoticeAudience::Teachers => $query->where('user_type', UserType::Teacher),
            NoticeAudience::Students => $query->where('user_type', UserType::Student)
                ->whereHas('studentProfile', fn (Builder $students) => $students
                    ->where('status', StudentStatus::Active)
                    ->when($this->class_id, fn (Builder $students) => $students->where('class_id', $this->class_id))),
            NoticeAudience::Parents => $query->where('user_type', UserType::Parent)
                ->when($this->class_id, fn (Builder $parents) => $parents->whereHas('parentProfile.children', fn (Builder $children) => $children->where($children->qualifyColumn('class_id'), $this->class_id))),
        };

        return $query;
    }

    public function state(): NoticeState
    {
        return match (true) {
            $this->published_at === null => NoticeState::Draft,
            $this->published_at->isFuture() => NoticeState::Scheduled,
            $this->expires_at?->isPast() === true => NoticeState::Expired,
            default => NoticeState::Live,
        };
    }

    public function isLive(): bool
    {
        return $this->state() === NoticeState::Live;
    }

    public function isVisibleTo(User $user): bool
    {
        return static::query()->whereKey($this->getKey())->visibleTo($user)->exists();
    }

    /**
     * Notice managers can open any notice (drafts too); everyone else only a
     * live notice addressed to them.
     */
    public function canBeReadBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->can('notices.view') || ($this->isLive() && $this->isVisibleTo($user));
    }

    public function attachmentName(): ?string
    {
        return $this->attachment ? basename($this->attachment) : null;
    }

    protected static function booted(): void
    {
        static::forceDeleted(function (Notice $notice) {
            if ($notice->attachment) {
                Storage::disk(self::DISK)->delete($notice->attachment);
            }
        });
    }
}
