<?php

namespace App\Enums;

enum FinanceCategoryType: string
{
    use EnumOptions;

    case Income = 'income';
    case Expense = 'expense';

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Income => 'success',
            self::Expense => 'danger',
        };
    }
}
