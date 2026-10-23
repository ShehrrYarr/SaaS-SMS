<?php

namespace App\Services\Finance;

use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\IncomeLedger;
use App\Models\Payment;
use App\Models\PayrollRecord;
use App\Services\Schools\SeedSchoolDefaults;
use RuntimeException;

/**
 * Automatic postings to the income and expense ledgers, so the ledgers and
 * finance reports show every rupee in and out:
 *
 *  - a fee payment → income under "Fee Collection" (removed if voided);
 *  - a paid payroll record → expense under "Salaries" (removed if the
 *    payment is undone).
 */
class Ledger
{
    public function __construct(private readonly SeedSchoolDefaults $defaults) {}

    public function category(string $key): FinanceCategory
    {
        $find = fn () => FinanceCategory::query()->where('system_key', $key)->first();

        if ($category = $find()) {
            return $category;
        }

        // Put back a missing system category.
        $this->defaults->handle(tenant());

        return $find() ?? throw new RuntimeException("Finance category [{$key}] is missing.");
    }

    public function postFeePayment(Payment $payment): IncomeLedger
    {
        $payment->loadMissing('invoice.student.user');

        return IncomeLedger::query()->create([
            'finance_category_id' => $this->category(FinanceCategory::FEES)->id,
            'source_type' => $payment->getMorphClass(),
            'source_id' => $payment->id,
            'title' => __('Fee receipt :number — :student', [
                'number' => $payment->receipt_number,
                'student' => $payment->invoice?->student?->user?->name,
            ]),
            'amount' => $payment->amount_paid,
            'income_date' => $payment->payment_date,
            'payment_method' => $payment->payment_method,
            'reference' => $payment->receipt_number,
            'recorded_by' => $payment->received_by,
        ]);
    }

    public function reverseFeePayment(Payment $payment): void
    {
        $payment->income()->get()->each->delete();
    }

    public function postSalary(PayrollRecord $record): Expense
    {
        $record->loadMissing('staff.user');

        return Expense::query()->create([
            'finance_category_id' => $this->category(FinanceCategory::SALARIES)->id,
            'source_type' => $record->getMorphClass(),
            'source_id' => $record->id,
            'title' => __('Salary :month — :name', ['month' => $record->monthLabel(), 'name' => $record->staff?->user?->name]),
            'amount' => $record->net_salary,
            'expense_date' => $record->paid_on,
            'payment_method' => $record->payment_method,
            'reference' => $record->staff?->employee_id,
            'recorded_by' => auth()->id(),
        ]);
    }

    public function reverseSalary(PayrollRecord $record): void
    {
        $record->expense()->get()->each->delete();
    }
}
