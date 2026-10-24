<?php

namespace Database\Seeders\Demo\Greenfield;

use App\Enums\PaymentMethod;
use App\Models\PayrollRecord;
use App\Services\Payroll\Payroll;
use Carbon\CarbonImmutable;

/**
 * Salaries for every month that has ended: HR generates them on the last
 * working day (absences and unpaid leave deducted), adds allowances and
 * recoveries, the principal approves, accounts pays by bank transfer. The
 * current month is left for HR to run.
 */
trait RunsPayroll
{
    /** Monthly allowance for heads of department and the exam coordinator. */
    private const ALLOWANCES = [
        'mehwish' => 'Head of section allowance', 'sobia' => 'Head of department allowance', 'sana' => 'Head of department allowance',
        'maryam' => 'Head of department allowance', 'naveed' => 'Head of department allowance', 'faisal' => 'Exam coordinator allowance',
    ];

    /** Teachers who invigilated the monthly test. */
    private const INVIGILATORS = ['sana', 'faisal', 'sobia', 'adeel', 'kiran', 'naveed', 'shahid', 'zubair'];

    private function planPayroll(): void
    {
        for ($month = $this->yearStart, $index = 0; $month->endOfMonth()->lt($this->today); $month = $month->addMonthNoOverflow(), $index++) {
            $period = $month->format('Y-m');
            $lastDay = $this->schoolDay($month->endOfMonth(), back: true);
            $nextDay = $this->schoolDay($month->endOfMonth()->addDay());

            $this->at($this->on($lastDay, '15:00'), fn () => $this->generatePayroll($period, $index));
            $this->at($this->on($nextDay, '10:30'), fn () => $this->approvePayroll($period));
            $this->at($this->on($this->addSchoolDays($nextDay, 1), '11:00'), fn () => $this->payPayroll($period, $this->addSchoolDays($nextDay, 1)));
        }
    }

    private function generatePayroll(string $period, int $index): void
    {
        $payroll = app(Payroll::class);
        $hr = $this->people['hina'];

        $this->as($hr, fn () => $payroll->generate($period, $hr));

        $keys = array_flip(array_map(fn ($staff) => $staff->id, $this->staff));

        foreach (PayrollRecord::query()->where('month_year', $period)->get() as $record) {
            $key = $keys[$record->staff_profile_id] ?? null;
            $allowances = [];
            $deductions = [];

            if (isset(self::ALLOWANCES[$key])) {
                $allowances[] = ['label' => self::ALLOWANCES[$key], 'amount' => 5000];
            }

            if ($index === 1 && in_array($key, self::INVIGILATORS, true)) {
                $allowances[] = ['label' => 'Exam invigilation', 'amount' => 2500];
            }

            if ($key === 'bilal') {
                $deductions[] = ['label' => 'Salary advance recovery', 'amount' => 5000];
            }

            if ($allowances !== [] || $deductions !== []) {
                $this->as($hr, fn () => $payroll->updateLines($record, $allowances, $deductions));
            }
        }
    }

    private function approvePayroll(string $period): void
    {
        foreach (PayrollRecord::query()->where('month_year', $period)->get() as $record) {
            $this->as($this->admin, fn () => app(Payroll::class)->approve($record, $this->admin));
        }
    }

    private function payPayroll(string $period, CarbonImmutable $day): void
    {
        $accountant = $this->people['omar'];

        foreach (PayrollRecord::query()->where('month_year', $period)->get() as $record) {
            $this->as($accountant, fn () => app(Payroll::class)->markPaid($record, $this->date($day), PaymentMethod::BankTransfer, $accountant));
        }
    }
}
