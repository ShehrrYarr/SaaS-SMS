<?php

namespace App\Models;

use App\Enums\HomeworkStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Homework extends TenantModel
{
    use SoftDeletes;

    // "homework" is uncountable to Laravel's pluralizer; the spec's table name is used.
    protected $table = 'homeworks';

    protected function casts(): array
    {
        return [
            'assigned_on' => 'date',
            'due_at' => 'datetime',
            'max_marks' => 'decimal:2',
            'status' => HomeworkStatus::class,
        ];
    }

    /**
     * @return BelongsTo<SubjectClass, $this>
     */
    public function subjectClass(): BelongsTo
    {
        return $this->belongsTo(SubjectClass::class);
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
}
