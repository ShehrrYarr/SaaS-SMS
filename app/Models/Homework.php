<?php

namespace App\Models;

use App\Enums\HomeworkStatus;
use App\Models\Concerns\AuditsChanges;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * An assignment set on a subject allocation, for every section it covers or
 * one of them (section_id). Students see it once published; closing it stops
 * new submissions.
 */
class Homework extends TenantModel
{
    use AuditsChanges, SoftDeletes;

    public const DISK = 'local';

    // "homework" is uncountable to Laravel's pluralizer; the spec's table name is used.
    protected $table = 'homeworks';

    protected function casts(): array
    {
        return [
            'assigned_on' => 'date',
            'due_at' => 'datetime',
            'max_marks' => 'decimal:2',
            'status' => HomeworkStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * Homework a student should do: published or closed, set on their class,
     * for their section or every section.
     *
     * @param  Builder<Homework>  $query
     */
    public function scopeForStudent(Builder $query, StudentProfile $student): void
    {
        $query->whereIn($query->qualifyColumn('status'), [HomeworkStatus::Published, HomeworkStatus::Closed])
            ->whereHas('subjectClass', fn (Builder $allocation) => $allocation
                ->where('class_id', $student->class_id ?? 0)
                ->where(fn (Builder $query) => $query->whereNull('section_id')->orWhere('section_id', $student->section_id ?? 0)))
            ->where(fn (Builder $query) => $query->whereNull($query->qualifyColumn('section_id'))->orWhere($query->qualifyColumn('section_id'), $student->section_id ?? 0));
    }

    public function isDraft(): bool
    {
        return $this->status === HomeworkStatus::Draft;
    }

    public function isPublished(): bool
    {
        return $this->status === HomeworkStatus::Published;
    }

    public function isClosed(): bool
    {
        return $this->status === HomeworkStatus::Closed;
    }

    /**
     * Students can still hand it in (late, after the due time).
     */
    public function acceptsSubmissions(): bool
    {
        return $this->isPublished();
    }

    public function isPastDue(?CarbonInterface $now = null): bool
    {
        return $this->due_at->lt($now ?? now());
    }

    /**
     * The section it was set for: its own, or the allocation's.
     */
    public function targetSectionId(): ?int
    {
        return $this->section_id ?? $this->subjectClass?->section_id;
    }

    /**
     * "Grade 5 · A — Mathematics".
     */
    public function groupLabel(): string
    {
        $allocation = $this->subjectClass;
        $section = $this->section ?? $allocation?->section;

        return trim(($allocation?->schoolClass?->name ?? '').($section ? ' · '.$section->name : ''))
            .($allocation?->subject ? ' — '.$allocation->subject->name : '');
    }

    /**
     * Due time in the school's timezone and formats.
     */
    public function dueLabel(): string
    {
        $school = tenant();

        return $school
            ? $school->localTime($this->due_at)->format($school->setting('date_format').' '.$school->setting('time_format'))
            : $this->due_at->toDayDateTimeString();
    }

    public function attachmentName(): ?string
    {
        return $this->attachment ? basename($this->attachment) : null;
    }

    /**
     * @return BelongsTo<SubjectClass, $this>
     */
    public function subjectClass(): BelongsTo
    {
        return $this->belongsTo(SubjectClass::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<HomeworkSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(HomeworkSubmission::class);
    }

    protected static function booted(): void
    {
        static::forceDeleted(function (Homework $homework) {
            Storage::disk(self::DISK)->deleteDirectory("schools/{$homework->school_id}/homework/{$homework->id}");
        });
    }
}
