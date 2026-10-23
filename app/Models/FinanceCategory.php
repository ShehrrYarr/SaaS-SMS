<?php

namespace App\Models;

use App\Enums\FinanceCategoryType;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An income or expense heading. System categories (fee collection,
 * salaries) receive automatic postings, so they can be renamed but not
 * removed.
 */
class FinanceCategory extends TenantModel
{
    use AuditsChanges, SoftDeletes;

    public const FEES = 'fees';

    public const SALARIES = 'salaries';

    protected function casts(): array
    {
        return [
            'type' => FinanceCategoryType::class,
            'is_active' => 'boolean',
        ];
    }

    public function isSystem(): bool
    {
        return $this->system_key !== null;
    }

    /**
     * Active categories of one type, by name.
     *
     * @param  Builder<FinanceCategory>  $query
     */
    public function scopeOfType(Builder $query, FinanceCategoryType $type): void
    {
        $query->where('type', $type)->where('is_active', true)->orderBy('name');
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * @return HasMany<IncomeLedger, $this>
     */
    public function incomes(): HasMany
    {
        return $this->hasMany(IncomeLedger::class);
    }
}
