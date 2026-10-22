<?php

namespace App\Models;

use App\Enums\BatchStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bulk invoice-generation run (one billing period, many students), tracked
 * so the UI can show live progress while the job runs.
 */
class InvoiceBatch extends TenantModel
{
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'status' => BatchStatus::class,
            'total_students' => 'integer',
            'processed' => 'integer',
            'invoices_created' => 'integer',
            'skipped' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
