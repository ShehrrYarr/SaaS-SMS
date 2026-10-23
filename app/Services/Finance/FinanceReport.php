<?php

namespace App\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\IncomeLedger;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SchoolClass;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the finance reports. Income comes from the income ledger
 * (fee receipts are posted there automatically), expenses from the expense
 * ledger (paid salaries likewise), so the two ledgers are the single source
 * for "money in" and "money out".
 */
class FinanceReport
{
    /**
     * @return array{income: float, fees: float, other_income: float, expenses: float, net: float}
     */
    public function summary(CarbonInterface $from, CarbonInterface $to): array
    {
        $feesCategory = FinanceCategory::query()->where('system_key', FinanceCategory::FEES)->value('id');

        $income = (float) $this->incomeQuery($from, $to)->sum('amount');
        $fees = (float) $this->incomeQuery($from, $to)->where('finance_category_id', $feesCategory ?? 0)->sum('amount');
        $expenses = (float) $this->expenseQuery($from, $to)->sum('amount');

        return [
            'income' => $income,
            'fees' => $fees,
            'other_income' => round($income - $fees, 2),
            'expenses' => $expenses,
            'net' => round($income - $expenses, 2),
        ];
    }

    /**
     * Totals per category, largest first.
     *
     * @return Collection<int, array{name: string, total: float}>
     */
    public function incomeByCategory(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->byCategory($this->incomeQuery($from, $to));
    }

    /**
     * @return Collection<int, array{name: string, total: float}>
     */
    public function expensesByCategory(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->byCategory($this->expenseQuery($from, $to));
    }

    /**
     * Fee receipts per payment method.
     *
     * @return Collection<int, array{method: string, label: string, count: int, total: float}>
     */
    public function collectionByMethod(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Payment::query()->valid()
            ->whereDate('payment_date', '>=', $from)->whereDate('payment_date', '<=', $to)
            ->selectRaw('payment_method, count(*) as receipts, sum(amount_paid) as total')
            ->groupBy('payment_method')
            ->get()
            ->map(fn (Payment $row) => [
                'method' => $row->payment_method->value,
                'label' => $row->payment_method->label(),
                'count' => (int) $row->receipts,
                'total' => (float) $row->total,
            ])
            ->sortByDesc('total')
            ->values();
    }

    /**
     * Income and expenses for each of the last $months months, ending with
     * the month of $end.
     *
     * @return array{labels: list<string>, income: list<float>, expenses: list<float>}
     */
    public function trend(CarbonInterface $end, int $months = 12): array
    {
        $first = Carbon::instance($end)->startOfMonth()->subMonths($months - 1);
        $last = Carbon::instance($end)->endOfMonth();

        $income = IncomeLedger::query()
            ->whereDate('income_date', '>=', $first)->whereDate('income_date', '<=', $last)
            ->selectRaw("DATE_FORMAT(income_date, '%Y-%m') as month, sum(amount) as total")
            ->groupBy('month')->toBase()->pluck('total', 'month');

        $expenses = Expense::query()
            ->whereDate('expense_date', '>=', $first)->whereDate('expense_date', '<=', $last)
            ->selectRaw("DATE_FORMAT(expense_date, '%Y-%m') as month, sum(amount) as total")
            ->groupBy('month')->toBase()->pluck('total', 'month');

        $result = ['labels' => [], 'income' => [], 'expenses' => []];

        for ($month = $first->copy(); $month->lte($last); $month->addMonth()) {
            $key = $month->format('Y-m');
            $result['labels'][] = $month->translatedFormat('M Y');
            $result['income'][] = round((float) ($income[$key] ?? 0), 2);
            $result['expenses'][] = round((float) ($expenses[$key] ?? 0), 2);
        }

        return $result;
    }

    /**
     * What is owed now: open invoices' balances, in total and per class
     * (the student's current class).
     *
     * @return array{total: float, overdue: float, invoices: int, by_class: Collection<int, array{class: string, students: int, total: float}>}
     */
    public function outstanding(): array
    {
        $balance = DB::raw('sum(invoices.total_amount - invoices.paid_amount) as owed');

        $byClass = Invoice::query()->open()
            ->join('student_profiles', 'student_profiles.id', '=', 'invoices.student_profile_id')
            ->selectRaw('student_profiles.class_id, count(distinct invoices.student_profile_id) as students')
            ->addSelect($balance)
            ->groupBy('student_profiles.class_id')
            ->toBase()
            ->get();

        $classes = SchoolClass::query()->whereIn('id', $byClass->pluck('class_id')->filter())->pluck('name', 'id');

        return [
            'total' => (float) Invoice::query()->open()->sum(DB::raw('total_amount - paid_amount')),
            'overdue' => (float) Invoice::query()->where('status', InvoiceStatus::Overdue)->sum(DB::raw('total_amount - paid_amount')),
            'invoices' => Invoice::query()->open()->count(),
            'by_class' => $byClass
                ->map(fn ($row) => [
                    'class' => $classes[$row->class_id] ?? __('No class'),
                    'students' => (int) $row->students,
                    'total' => round((float) $row->owed, 2),
                ])
                ->sortByDesc('total')
                ->values(),
        ];
    }

    /**
     * Students with overdue balances, largest first.
     *
     * @return Collection<int, object{student_profile_id: int, invoices: int, owed: float, oldest_due: string}>
     */
    public function defaulters(int $limit = 50): Collection
    {
        return Invoice::query()
            ->where('status', InvoiceStatus::Overdue)
            ->selectRaw('student_profile_id, count(*) as invoices, sum(total_amount - paid_amount) as owed, min(due_date) as oldest_due')
            ->groupBy('student_profile_id')
            ->orderByDesc('owed')
            ->limit($limit)
            ->toBase()
            ->get();
    }

    /**
     * Every ledger entry in the period, oldest first, for export.
     *
     * @return Collection<int, array{date: string, ledger: string, category: string, description: string, reference: string, method: string, amount: float}>
     */
    public function entries(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $map = fn (string $ledger, string $dateColumn) => fn (Expense|IncomeLedger $entry) => [
            'date' => $entry->{$dateColumn}->toDateString(),
            'ledger' => $ledger,
            'category' => (string) $entry->category?->name,
            'description' => $entry->title,
            'reference' => (string) $entry->reference,
            'method' => (string) $entry->payment_method?->label(),
            'amount' => (float) $entry->amount,
        ];

        $categories = fn ($query) => $query->withTrashed();

        return $this->incomeQuery($from, $to)->with(['category' => $categories])->get()->map($map(__('Income'), 'income_date'))
            ->concat($this->expenseQuery($from, $to)->with(['category' => $categories])->get()->map($map(__('Expense'), 'expense_date')))
            ->sortBy('date')
            ->values();
    }

    private function incomeQuery(CarbonInterface $from, CarbonInterface $to)
    {
        return IncomeLedger::query()->whereDate('income_date', '>=', $from)->whereDate('income_date', '<=', $to);
    }

    private function expenseQuery(CarbonInterface $from, CarbonInterface $to)
    {
        return Expense::query()->whereDate('expense_date', '>=', $from)->whereDate('expense_date', '<=', $to);
    }

    /**
     * @return Collection<int, array{name: string, total: float}>
     */
    private function byCategory($query): Collection
    {
        $totals = $query->selectRaw('finance_category_id, sum(amount) as total')->groupBy('finance_category_id')->toBase()->pluck('total', 'finance_category_id');
        $names = FinanceCategory::query()->withTrashed()->whereIn('id', $totals->keys())->pluck('name', 'id');

        return $totals->map(fn ($total, $id) => ['name' => $names[$id] ?? __('Unknown'), 'total' => round((float) $total, 2)])
            ->sortByDesc('total')
            ->values();
    }
}
