<?php

namespace Database\Seeders\Demo\Greenfield;

use App\Enums\FinanceCategoryType;
use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\IncomeLedger;

/**
 * The running costs and other income accounts records each month (fee
 * payments and salaries post to the ledger by themselves).
 */
trait KeepsAccounts
{
    /**
     * [category, title, day of month, amount range, method, reference prefix]
     */
    private const MONTHLY_EXPENSES = [
        ['Utilities', 'Electricity bill (LESCO)', 9, [95000, 145000], 'bank_transfer', 'LESCO-'],
        ['Utilities', 'Gas bill (SNGPL)', 11, [6000, 12000], 'bank_transfer', 'SNGPL-'],
        ['Utilities', 'Internet and phone (PTCL)', 6, [12500, 12500], 'bank_transfer', 'PTCL-'],
        ['Utilities', 'Water and sewerage (WASA)', 14, [3200, 3200], 'cash', null],
        ['Rent', 'Junior Campus rent', 5, [250000, 250000], 'cheque', 'CHQ '],
        ['Transport', 'Diesel for school buses', 7, [65000, 82000], 'cash', null],
        ['Transport', 'Diesel for school buses', 21, [65000, 82000], 'cash', null],
        ['Other Expenses', 'Security services (monthly contract)', 3, [70000, 70000], 'bank_transfer', 'INV-SEC-'],
        ['Stationery & Supplies', 'Whiteboard markers, chart paper and printer toner', 16, [14000, 28000], 'cash', null],
    ];

    /**
     * One-offs: [category, title, days after the year starts, amount, method]
     */
    private const ONE_OFF_EXPENSES = [
        ['Maintenance', 'Whitewash and repairs before the new session', -12, 85000, 'cash'],
        ['Maintenance', 'AC servicing (main block)', -5, 18000, 'cash'],
        ['Events', 'Independence Day celebrations', 13, 45000, 'cash'],
        ['Maintenance', 'Generator repair', 34, 22500, 'cash'],
        ['Maintenance', 'Plumbing work, junior washrooms', 49, 6500, 'cash'],
        ['Events', 'Science fair materials', 58, 30000, 'cash'],
        ['Stationery & Supplies', 'Exam answer sheets printing', 70, 16000, 'cash'],
    ];

    private function planAccounts(): void
    {
        $accountant = $this->people['omar'];
        $categories = FinanceCategory::query()->get()->groupBy(fn (FinanceCategory $category) => $category->type->value.':'.$category->name)->map->first();
        $expense = fn (string $name) => $categories[FinanceCategoryType::Expense->value.':'.$name]->id;
        $income = fn (string $name) => $categories[FinanceCategoryType::Income->value.':'.$name]->id;

        for ($month = $this->yearStart, $index = 0; $month->lte($this->today); $month = $month->addMonthNoOverflow(), $index++) {
            foreach (self::MONTHLY_EXPENSES as [$category, $title, $day, [$low, $high], $method, $prefix]) {
                $amount = round($this->dice->int($low, $high) / 100) * 100;
                $reference = $prefix ? $prefix.$this->dice->digits(8) : null;
                $when = $this->on($this->schoolDay($month->setDay($day)), sprintf('%02d:%02d', $this->dice->int(10, 15), $this->dice->int(0, 59)));

                $this->at($when, fn () => $this->as($accountant, fn () => Expense::query()->create([
                    'finance_category_id' => $expense($category),
                    'title' => $title,
                    'amount' => $amount,
                    'expense_date' => tenant()->today()->toDateString(),
                    'payment_method' => PaymentMethod::from($method),
                    'reference' => $reference,
                    'recorded_by' => $accountant->id,
                ])));
            }

            // The canteen pays its rent, the uniform shop its commission.
            foreach ([['Canteen contract: monthly rent', 60000, 5, 'bank_transfer'], ['Uniform shop commission', 18500, 25, 'cash']] as [$title, $amount, $day, $method]) {
                $when = $this->on($this->schoolDay($month->setDay($day)), '12:30');

                $this->at($when, fn () => $this->as($accountant, fn () => IncomeLedger::query()->create([
                    'finance_category_id' => $income('Other Income'),
                    'title' => $title,
                    'amount' => $amount,
                    'income_date' => tenant()->today()->toDateString(),
                    'payment_method' => PaymentMethod::from($method),
                    'recorded_by' => $accountant->id,
                ])));
            }
        }

        foreach (self::ONE_OFF_EXPENSES as [$category, $title, $offset, $amount, $method]) {
            $when = $this->on($this->schoolDay($this->yearStart->addDays($offset)), '11:45');

            $this->at($when, fn () => $this->as($accountant, fn () => Expense::query()->create([
                'finance_category_id' => $expense($category),
                'title' => $title,
                'amount' => $amount,
                'expense_date' => tenant()->today()->toDateString(),
                'payment_method' => PaymentMethod::from($method),
                'recorded_by' => $accountant->id,
            ])));
        }

        $this->at($this->on($this->schoolDay($this->yearStart->addDays(40)), '13:00'), fn () => $this->as($accountant, fn () => IncomeLedger::query()->create([
            'finance_category_id' => $income('Donations'),
            'title' => 'Alumni association: library fund',
            'amount' => 250000,
            'income_date' => tenant()->today()->toDateString(),
            'payment_method' => PaymentMethod::Cheque,
            'reference' => 'CHQ '.$this->dice->digits(8),
            'description' => 'For new books and furniture in the library.',
            'recorded_by' => $accountant->id,
        ])));
    }
}
