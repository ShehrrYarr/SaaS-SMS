<?php

namespace App\Models;

use App\Enums\BookIssueStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookIssue extends TenantModel
{
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'due_on' => 'date',
            'returned_on' => 'date',
            'fine_amount' => 'decimal:2',
            'fine_paid' => 'boolean',
            'status' => BookIssueStatus::class,
        ];
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

    public function isOverdue(): bool
    {
        return $this->status === BookIssueStatus::Issued && $this->due_on->isPast();
    }
}
