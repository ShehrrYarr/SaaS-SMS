<?php

namespace App\Models;

use App\Enums\FinanceCategoryType;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinanceCategory extends TenantModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => FinanceCategoryType::class,
            'is_active' => 'boolean',
        ];
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
