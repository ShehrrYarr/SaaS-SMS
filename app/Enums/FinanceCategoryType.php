<?php

namespace App\Enums;

use App\Models\Expense;
use App\Models\IncomeLedger;

/**
 * The two ledgers. Each has its own model, date column and permission
 * module (income.*, expenses.*).
 */
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

    /**
     * @return class-string<Expense|IncomeLedger>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Income => IncomeLedger::class,
            self::Expense => Expense::class,
        };
    }

    public function dateColumn(): string
    {
        return match ($this) {
            self::Income => 'income_date',
            self::Expense => 'expense_date',
        };
    }

    /**
     * "income.create", "expenses.view".
     */
    public function permission(string $action): string
    {
        return match ($this) {
            self::Income => 'income',
            self::Expense => 'expenses',
        }.'.'.$action;
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Income => __('Income'),
            self::Expense => __('Expenses'),
        };
    }
}
