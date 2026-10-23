<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\AuditsChanges;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A bill for one student. total = subtotal - discount + late fee; the status
 * follows the balance and the due date (see App\Services\Fees\Invoices).
 */
class Invoice extends TenantModel
{
    use AuditsChanges, SoftDeletes;

    /** Raising, changing, cancelling and paying are logged with their context by the fee services. */
    protected static $recordEvents = ['deleted'];

    /** Statuses that still expect money. */
    public const OPEN = [InvoiceStatus::Unpaid, InvoiceStatus::Partial, InvoiceStatus::Overdue];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'late_fee_amount' => 'decimal:2',
            'late_fee_waived_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'status' => InvoiceStatus::class,
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function balance(): Attribute
    {
        return Attribute::get(fn () => (string) BigDecimal::of($this->total_amount)->minus($this->paid_amount)->toScale(2));
    }

    public function balanceAmount(): float
    {
        return round((float) $this->total_amount - (float) $this->paid_amount, 2);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isCancelled(): bool
    {
        return $this->status === InvoiceStatus::Cancelled;
    }

    /**
     * @param  Builder<Invoice>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn($query->qualifyColumn('status'), self::OPEN);
    }

    /**
     * "October 2026" for a billing period, or null.
     */
    public function periodLabel(): ?string
    {
        return $this->billing_period ? Carbon::createFromFormat('!Y-m', $this->billing_period)->translatedFormat('F Y') : null;
    }

    /**
     * @return BelongsTo<StudentProfile, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return BelongsTo<InvoiceBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(InvoiceBatch::class, 'invoice_batch_id');
    }

    /**
     * @return BelongsTo<FeeStructure, $this>
     */
    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
