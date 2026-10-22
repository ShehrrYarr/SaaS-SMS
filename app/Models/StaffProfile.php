<?php

namespace App\Models;

use App\Enums\Gender;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StaffProfile extends TenantModel
{
    use SoftDeletes;

    protected $hidden = ['bank_details'];

    protected function casts(): array
    {
        return [
            'joining_date' => 'date',
            'dob' => 'date',
            'gender' => Gender::class,
            'salary' => 'decimal:2',
            'bank_details' => 'encrypted:array',
            'emergency_contact' => 'array',
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
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * Subjects this teacher is allocated to teach.
     *
     * @return HasMany<SubjectClass, $this>
     */
    public function subjectAllocations(): HasMany
    {
        return $this->hasMany(SubjectClass::class, 'teacher_id');
    }

    /**
     * Sections this teacher is the class teacher of.
     *
     * @return HasMany<Section, $this>
     */
    public function classTeacherSections(): HasMany
    {
        return $this->hasMany(Section::class, 'class_teacher_id');
    }

    /**
     * @return HasMany<StaffAttendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(StaffAttendance::class);
    }

    /**
     * @return HasMany<LeaveBalance, $this>
     */
    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    /**
     * @return MorphMany<LeaveRequest, $this>
     */
    public function leaveRequests(): MorphMany
    {
        return $this->morphMany(LeaveRequest::class, 'applicant');
    }

    /**
     * @return HasMany<PayrollRecord, $this>
     */
    public function payrollRecords(): HasMany
    {
        return $this->hasMany(PayrollRecord::class);
    }
}
