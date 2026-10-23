<?php

namespace App\Services\Payroll;

use App\Enums\LeaveStatus;
use App\Enums\PaymentMethod;
use App\Enums\PayrollStatus;
use App\Enums\StaffAttendanceStatus;
use App\Enums\UserStatus;
use App\Models\LeaveRequest;
use App\Models\PayrollRecord;
use App\Models\StaffAttendance;
use App\Models\StaffProfile;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Attendance\SchoolCalendar;
use App\Services\Fees\FeeCalculator;
use App\Services\Finance\Ledger;
use App\Support\Portal;
use App\Tables\ActionFailed;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monthly payroll.
 *
 * Generating a month creates a draft for every active staff member with a
 * salary. A draft is the basic salary, less automatic deductions worked out
 * from the staff register and approved leave, plus or minus the allowances
 * and deductions HR adds by hand:
 *
 *  - the daily rate is the salary divided by the month's working days;
 *  - days before the joining date aren't paid;
 *  - unpaid leave is deducted; paid leave isn't;
 *  - absent days (and half of each half day) are deducted when the school
 *    deducts absences (Settings → Payroll);
 *  - days with nothing in the register count as worked.
 *
 * Draft → Approved (locked) → Paid (posted to expenses, payslip shared with
 * the staff member). Each step can be undone by someone with the same
 * permission while nothing later has happened.
 */
class Payroll
{
    public function __construct(
        private readonly SchoolCalendar $calendar,
        private readonly Ledger $ledger,
    ) {}

    public static function isMonth(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1;
    }

    public static function monthLabel(string $month): string
    {
        return Carbon::createFromFormat('!Y-m', $month)->translatedFormat('F Y');
    }

    /**
     * Active staff with a salary, employed by the end of the month, who
     * have no payroll record for it yet.
     *
     * @return Collection<int, StaffProfile>
     */
    public function pendingStaff(string $month): Collection
    {
        $end = Carbon::createFromFormat('!Y-m', $month)->endOfMonth();

        return StaffProfile::query()
            ->with('user')
            ->where('salary', '>', 0)
            ->whereHas('user', fn ($query) => $query->where('status', UserStatus::Active))
            ->where(fn ($query) => $query->whereNull('joining_date')->orWhereDate('joining_date', '<=', $end))
            ->whereDoesntHave('payrollRecords', fn ($query) => $query->withTrashed()->where('month_year', $month))
            ->get();
    }

    /**
     * @return int drafts created
     */
    public function generate(string $month, User $by): int
    {
        $created = 0;

        foreach ($this->pendingStaff($month) as $staff) {
            DB::transaction(function () use ($staff, $month, $by) {
                PayrollRecord::query()->create([
                    ...$this->calculate($staff, $month),
                    'staff_profile_id' => $staff->id,
                    'month_year' => $month,
                    'status' => PayrollStatus::Draft,
                    'generated_by' => $by->id,
                ]);
            });

            $created++;
        }

        if ($created > 0) {
            activity('payroll_record')->causedBy($by)->withProperties(['month' => $month, 'created' => $created])->log('Payroll generated');
        }

        return $created;
    }

    /**
     * The record's figures for a staff member and month. Hand-entered lines
     * are kept; automatic ones are worked out afresh.
     *
     * @param  list<array{label: string, amount: float}>  $allowances
     * @param  list<array{label: string, amount: float}>  $deductions  hand-entered only
     * @return array<string, mixed>
     */
    public function calculate(StaffProfile $staff, string $month, array $allowances = [], array $deductions = []): array
    {
        $start = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $schoolDays = $this->calendar->schoolDays($start, $end);
        $working = count($schoolDays);

        $attendance = StaffAttendance::query()
            ->where('staff_profile_id', $staff->id)
            ->whereDate('date', '>=', $start)->whereDate('date', '<=', $end)
            ->get()
            ->keyBy(fn (StaffAttendance $record) => $record->date->toDateString());

        $leave = LeaveRequest::query()
            ->with('leaveType')
            ->where('applicant_type', $staff->getMorphClass())
            ->where('applicant_id', $staff->id)
            ->where('status', LeaveStatus::Approved)
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->get();

        $counts = ['working_days' => $working, 'present' => 0.0, 'absent' => 0.0, 'half_days' => 0, 'paid_leave' => 0.0, 'unpaid_leave' => 0.0, 'not_employed' => 0];
        $joined = $staff->joining_date?->copy()->startOfDay();

        foreach ($schoolDays as $day) {
            if ($joined && $day->lt($joined)) {
                $counts['not_employed']++;

                continue;
            }

            $request = $leave->first(fn (LeaveRequest $request) => $day->betweenIncluded($request->start_date, $request->end_date));
            $portion = $request && (float) $request->days === 0.5 ? 0.5 : 1.0;

            if ($request) {
                $counts[$request->leaveType?->is_paid === false ? 'unpaid_leave' : 'paid_leave'] += $portion;
            }

            if ($portion === 1.0 && $request) {
                continue;
            }

            // The rest of the day (all of it without leave) from the register.
            $status = $attendance->get($day->toDateString())?->status;
            $share = $request ? 0.5 : 1.0;

            if ($status === StaffAttendanceStatus::Absent) {
                $counts['absent'] += $share;
            } elseif ($status === StaffAttendanceStatus::HalfDay) {
                $counts['half_days']++;
                $counts['absent'] += $share / 2;
                $counts['present'] += $share / 2;
            } elseif ($status === StaffAttendanceStatus::OnLeave) {
                // Marked on leave in the register without an approved request: HR's call, paid.
                $counts['paid_leave'] += $share;
            } else {
                $counts['present'] += $share;
            }
        }

        $basic = FeeCalculator::cents($staff->salary);
        $rate = $working > 0 ? $basic / $working : 0;
        $auto = [];

        if ($counts['not_employed'] > 0) {
            $auto[] = ['label' => __('Before joining (:days days)', ['days' => $counts['not_employed']]), 'cents' => (int) round($rate * $counts['not_employed'])];
        }

        if ($counts['unpaid_leave'] > 0) {
            $auto[] = ['label' => __('Unpaid leave (:days days)', ['days' => self::days($counts['unpaid_leave'])]), 'cents' => (int) round($rate * $counts['unpaid_leave'])];
        }

        if ($counts['absent'] > 0 && tenant()->setting('payroll.deduct_absences')) {
            $auto[] = ['label' => __('Absent (:days days)', ['days' => self::days($counts['absent'])]), 'cents' => (int) round($rate * $counts['absent'])];
        }

        $manualAllowances = self::lines($allowances);
        $manualDeductions = self::lines($deductions);

        $bonuses = array_sum(array_column($manualAllowances, 'cents'));
        $deducted = min(array_sum(array_column($auto, 'cents')) + array_sum(array_column($manualDeductions, 'cents')), $basic + $bonuses);

        $toLines = fn (array $lines, bool $automatic) => array_map(fn (array $line) => [
            'label' => $line['label'],
            'amount' => $line['cents'] / 100,
            'auto' => $automatic,
        ], $lines);

        return [
            'basic_salary' => $basic / 100,
            'bonuses' => $bonuses / 100,
            'deductions' => $deducted / 100,
            'net_salary' => ($basic + $bonuses - $deducted) / 100,
            'working_days' => $working,
            'present_days' => $counts['present'],
            'breakdown' => [
                'allowances' => $toLines($manualAllowances, false),
                'deductions' => [...$toLines($auto, true), ...$toLines($manualDeductions, false)],
                'attendance' => $counts,
            ],
        ];
    }

    /**
     * Work a draft out again (after attendance or leave changed), keeping
     * the hand-entered lines.
     *
     * @throws ActionFailed
     */
    public function recalculate(PayrollRecord $record): void
    {
        $this->ensureDraft($record);

        $record->update($this->calculate(
            $record->staff()->firstOrFail(),
            $record->month_year,
            self::manual($record->breakdown['allowances'] ?? []),
            self::manual($record->breakdown['deductions'] ?? []),
        ));
    }

    /**
     * Replace a draft's hand-entered allowances and deductions.
     *
     * @param  list<array{label: string, amount: float|string}>  $allowances
     * @param  list<array{label: string, amount: float|string}>  $deductions
     *
     * @throws ActionFailed
     */
    public function updateLines(PayrollRecord $record, array $allowances, array $deductions): void
    {
        $this->ensureDraft($record);

        $record->update($this->calculate($record->staff()->firstOrFail(), $record->month_year, $allowances, $deductions));
    }

    /**
     * @throws ActionFailed
     */
    public function approve(PayrollRecord $record, User $by): void
    {
        $this->ensureDraft($record);

        $record->update(['status' => PayrollStatus::Approved]);
    }

    /**
     * @throws ActionFailed
     */
    public function reopen(PayrollRecord $record, User $by): void
    {
        if ($record->status !== PayrollStatus::Approved) {
            throw new ActionFailed(__('Only approved payroll can go back to draft.'));
        }

        $record->update(['status' => PayrollStatus::Draft]);
    }

    /**
     * @throws ActionFailed
     */
    public function markPaid(PayrollRecord $record, CarbonInterface $paidOn, PaymentMethod $method, User $by): void
    {
        if ($record->status !== PayrollStatus::Approved) {
            throw new ActionFailed(__('Approve the payroll before paying it.'));
        }

        DB::transaction(function () use ($record, $paidOn, $method) {
            $record->update([
                'status' => PayrollStatus::Paid,
                'paid_on' => $paidOn->toDateString(),
                'payment_method' => $method,
            ]);

            $this->ledger->postSalary($record);
        });

        $this->notifyPaid($record);
    }

    /**
     * @throws ActionFailed
     */
    public function undoPayment(PayrollRecord $record, User $by): void
    {
        if (! $record->isPaid()) {
            throw new ActionFailed(__('This salary hasn\'t been paid.'));
        }

        DB::transaction(function () use ($record) {
            $this->ledger->reverseSalary($record);

            $record->update(['status' => PayrollStatus::Approved, 'paid_on' => null, 'payment_method' => null]);
        });
    }

    /**
     * Remove a draft, so it can be generated again.
     *
     * @throws ActionFailed
     */
    public function discard(PayrollRecord $record): void
    {
        $this->ensureDraft($record);

        $record->forceDelete();
    }

    /**
     * @throws ActionFailed
     */
    private function ensureDraft(PayrollRecord $record): void
    {
        if (! $record->isDraft()) {
            throw new ActionFailed(__('Only draft payroll can be changed. Send it back to draft first.'));
        }
    }

    private function notifyPaid(PayrollRecord $record): void
    {
        $user = $record->staff()->with('user')->first()?->user;

        if (! $user || ! $user->isActive()) {
            return;
        }

        $user->notify(new SystemNotification(
            title: __('Salary paid for :month', ['month' => $record->monthLabel()]),
            message: __('Your salary slip is ready.'),
            url: Portal::selfServiceUrl($user, 'my-payslips'),
            icon: 'ri-money-dollar-circle-line',
            color: 'success',
        ));
    }

    /**
     * Clean hand-entered lines: label and a positive amount, in cents.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array{label: string, cents: int}>
     */
    private static function lines(array $lines): array
    {
        $clean = [];

        foreach ($lines as $line) {
            $label = trim((string) ($line['label'] ?? ''));
            $cents = FeeCalculator::cents($line['amount'] ?? 0);

            if ($label !== '' && $cents > 0) {
                $clean[] = ['label' => mb_substr($label, 0, 100), 'cents' => $cents];
            }
        }

        return $clean;
    }

    /**
     * The hand-entered lines of a stored breakdown section.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array{label: string, amount: float}>
     */
    public static function manual(array $lines): array
    {
        return array_values(array_map(
            fn (array $line) => ['label' => (string) $line['label'], 'amount' => (float) $line['amount']],
            array_filter($lines, fn (array $line) => ! ($line['auto'] ?? false)),
        ));
    }

    private static function days(float $days): string
    {
        return rtrim(rtrim(number_format($days, 1, '.', ''), '0'), '.');
    }
}
