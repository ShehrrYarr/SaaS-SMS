<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A fee payment against an invoice. Mistakes are voided (with a reason), not
 * edited, so every receipt number stays accounted for.
 */
class Payment extends TenantModel
{
    use AuditsChanges, SoftDeletes;

    protected function casts(): array
    {
        return [
            'amount_paid' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'payment_date' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function scopeValid(Builder $query): void
    {
        $query->whereNull('voided_at');
    }
}
