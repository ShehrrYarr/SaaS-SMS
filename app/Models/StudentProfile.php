<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;

class StudentProfile extends TenantModel
{
    use AuditsChanges {
        getActivitylogOptions as defaultActivitylogOptions;
    }
    use SoftDeletes;

    /** Admissions and removals are logged by StudentRecords with their context; edits by the model. */
    protected static $recordEvents = ['updated'];

    public function getActivitylogOptions(): LogOptions
    {
        // Health details stay out of the activity log.
        return $this->defaultActivitylogOptions()->logExcept(['created_at', 'updated_at', 'medical_notes']);
    }

    /**
     * @param  Builder<StudentProfile>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), StudentStatus::Active);
    }

    public function isActive(): bool
    {
        return $this->status === StudentStatus::Active;
    }

    /**
     * "Grade 5 · B", or just the class.
     */
    public function placementLabel(): ?string
    {
        if (! $this->schoolClass) {
            return null;
        }

        return $this->schoolClass->name.($this->section ? ' · '.$this->section->name : '');
    }

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'dob' => 'date',
            'admission_date' => 'date',
            'status' => StudentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Current class; the per-year history is in enrollments().
     *
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * @return BelongsToMany<ParentProfile, $this>
     */
    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(ParentProfile::class, 'parent_student')
            ->using(ParentStudent::class)
            ->withPivot(['id', 'relation', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<StudentEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    /**
     * @return HasMany<StudentAttendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(StudentAttendance::class);
    }

    /**
     * @return HasMany<Mark, $this>
     */
    public function marks(): HasMany
    {
        return $this->hasMany(Mark::class);
    }

    /**
     * @return HasMany<HomeworkSubmission, $this>
     */
    public function homeworkSubmissions(): HasMany
    {
        return $this->hasMany(HomeworkSubmission::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return BelongsToMany<FeeDiscount, $this>
     */
    public function feeDiscounts(): BelongsToMany
    {
        return $this->belongsToMany(FeeDiscount::class, 'student_fee_discounts')
            ->using(StudentFeeDiscount::class)
            ->withPivot(['id', 'valid_from', 'valid_until', 'remarks', 'approved_by'])
            ->withTimestamps();
    }

    /**
     * @return MorphMany<LeaveRequest, $this>
     */
    public function leaveRequests(): MorphMany
    {
        return $this->morphMany(LeaveRequest::class, 'applicant');
    }

    /**
     * @return HasMany<StudentTransport, $this>
     */
    public function transportAssignments(): HasMany
    {
        return $this->hasMany(StudentTransport::class);
    }

    /**
     * @return HasMany<StudentHostel, $this>
     */
    public function hostelAllocations(): HasMany
    {
        return $this->hasMany(StudentHostel::class);
    }
}
