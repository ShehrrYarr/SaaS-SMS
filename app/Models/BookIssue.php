<?php

namespace App\Models;

use App\Enums\BookIssueStatus;
use App\Enums\FineStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One copy of a book lent to a student or a member of staff. A loan is
 * Issued until the copy comes back (Returned) or is reported Lost; the fine
 * for keeping it late or losing it is recorded on the loan.
 */
class BookIssue extends TenantModel
{
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'due_on' => 'date',
            'returned_on' => 'date',
            'renewals' => 'integer',
            'fine_amount' => 'decimal:2',
            'fine_status' => FineStatus::class,
            'fine_settled_at' => 'datetime',
            'status' => BookIssueStatus::class,
            'reminded_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<BookIssue>  $query
     */
    public function scopeOut(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), BookIssueStatus::Issued);
    }

    /**
     * Loans still out after their due date.
     *
     * @param  Builder<BookIssue>  $query
     */
    public function scopeOverdue(Builder $query, ?CarbonInterface $today = null): void
    {
        $query->out()->whereDate($query->qualifyColumn('due_on'), '<', ($today ?? tenant()->today())->toDateString());
    }

    /**
     * @param  Builder<BookIssue>  $query
     */
    public function scopeFineOwed(Builder $query): void
    {
        $query->where($query->qualifyColumn('fine_status'), FineStatus::Unpaid);
    }

    /**
     * @return BelongsTo<LibraryBook, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(LibraryBook::class, 'library_book_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * The fee invoice the fine was added to.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isOut(): bool
    {
        return $this->status === BookIssueStatus::Issued;
    }

    public function isOverdue(?CarbonInterface $today = null): bool
    {
        return $this->isOut() && $this->due_on->toDateString() < ($today ?? tenant()->today())->toDateString();
    }

    /**
     * Whole days past the due date: until $on (today) for a loan still out,
     * until the return date for one that came back.
     */
    public function daysLate(?CarbonInterface $on = null): int
    {
        $until = $this->isOut() ? ($on ?? tenant()->today()) : ($this->returned_on ?? $on ?? tenant()->today());

        return max(0, static::daysBetween($this->due_on, $until));
    }

    /**
     * Calendar days from one date to another, whatever their timezones.
     */
    public static function daysBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) Carbon::parse($from->toDateString())->diffInDays(Carbon::parse($to->toDateString()), false);
    }

    public function hasFineOwed(): bool
    {
        return $this->fine_status === FineStatus::Unpaid;
    }
}
