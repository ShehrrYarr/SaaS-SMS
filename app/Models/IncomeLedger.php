<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Money coming in. Fee payments are posted here automatically (source =
 * the Payment); those entries follow the receipt (voiding it removes them).
 */
class IncomeLedger extends TenantModel
{
    use AuditsChanges, SoftDeletes;

    /** Disk for proof files: private, served through an authorised route. */
    public const DISK = 'local';

    protected static function booted(): void
    {
        static::forceDeleted(function (IncomeLedger $income) {
            if ($income->proof_receipt) {
                Storage::disk(self::DISK)->delete($income->proof_receipt);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'income_date' => 'date',
            'payment_method' => PaymentMethod::class,
        ];
    }

    public function isAutomatic(): bool
    {
        return $this->source_type !== null;
    }

    /**
     * @return BelongsTo<FinanceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    /**
     * Where the income came from when it was posted automatically (e.g. a fee Payment).
     *
     * @return MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
