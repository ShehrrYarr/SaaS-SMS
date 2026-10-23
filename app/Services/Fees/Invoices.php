<?php

namespace App\Services\Fees;

use App\Enums\FeeFrequency;
use App\Enums\InvoiceStatus;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\InvoiceBatch;
use App\Models\InvoiceItem;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Attendance\SchoolCalendar;
use App\Services\Numbering\Sequences;
use App\Tables\ActionFailed;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Raising, correcting and cancelling invoices.
 *
 * Invoice numbers run per calendar year of issue: INV-2026-0001. A fee is
 * billed at most once per student per billing period (a one-time fee at
 * most once ever), unless the earlier invoice was cancelled.
 *
 * Status follows the money: Paid when nothing is owed, Overdue when money
 * is owed after the due date, otherwise Partial or Unpaid.
 */
class Invoices
{
    public function __construct(
        private readonly FeeCalculator $calculator,
        private readonly Sequences $sequences,
        private readonly SchoolCalendar $calendar,
    ) {}

    public function nextNumber(CarbonInterface $issueDate): string
    {
        $prefix = tenant()->setting('numbering.invoice_prefix').$issueDate->year.'-';

        return $this->sequences->next('invoice-'.$issueDate->year, $prefix,
            fn (string $number) => Invoice::query()->withTrashed()->where('invoice_number', $number)->exists());
    }

    /**
     * Lines for the fees that apply to the student: their class's (or every
     * class's) fees not already billed for the period.
     *
     * @param  Collection<int, FeeStructure>  $fees
     * @return list<array<string, mixed>>
     */
    public function linesFor(StudentProfile $student, Collection $fees, ?string $period, CarbonInterface $on): array
    {
        $lines = [];

        foreach ($fees as $fee) {
            if ($fee->class_id !== null && $fee->class_id !== $student->class_id) {
                continue;
            }

            if ($this->alreadyBilled($student, $fee, $period)) {
                continue;
            }

            $lines[] = $this->calculator->line($student, $fee, $on, $period);
        }

        return $lines;
    }

    public function alreadyBilled(StudentProfile $student, FeeStructure $fee, ?string $period): bool
    {
        $oneTime = $fee->frequency === FeeFrequency::OneTime;

        if (! $oneTime && $period === null) {
            return false;
        }

        return InvoiceItem::query()
            ->where('fee_structure_id', $fee->id)
            ->whereHas('invoice', fn ($query) => $query
                ->where('student_profile_id', $student->id)
                ->where('status', '!=', InvoiceStatus::Cancelled)
                ->when(! $oneTime, fn ($query) => $query->where('billing_period', $period)))
            ->exists();
    }

    /**
     * @param  list<array<string, mixed>>  $lines  from linesFor() / FeeCalculator
     *
     * @throws ActionFailed
     */
    public function create(
        StudentProfile $student,
        array $lines,
        CarbonInterface $issueDate,
        CarbonInterface $dueDate,
        ?string $period,
        User $by,
        ?string $notes = null,
        ?InvoiceBatch $batch = null,
    ): Invoice {
        if ($lines === []) {
            throw new ActionFailed(__('There is nothing to bill.'));
        }

        if ($dueDate->lt($issueDate->copy()->startOfDay())) {
            throw new ActionFailed(__('The due date can\'t be before the issue date.'));
        }

        return DB::transaction(function () use ($student, $lines, $issueDate, $dueDate, $period, $by, $notes, $batch) {
            $subtotal = array_sum(array_map(fn (array $line) => FeeCalculator::cents($line['amount']), $lines));
            $discount = array_sum(array_map(fn (array $line) => FeeCalculator::cents($line['discount_amount']), $lines));
            $feeIds = array_values(array_unique(array_filter(array_column($lines, 'fee_structure_id'))));

            $invoice = new Invoice([
                'student_profile_id' => $student->id,
                'academic_year_id' => $this->calendar->yearFor($period ? FeeSchedule::periodStart($period) : $issueDate)?->id,
                'invoice_batch_id' => $batch?->id,
                'fee_structure_id' => count($lines) === 1 && count($feeIds) === 1 ? $feeIds[0] : null,
                'invoice_number' => $this->nextNumber($issueDate),
                'billing_period' => $period,
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'subtotal' => $subtotal / 100,
                'discount_amount' => $discount / 100,
                'late_fee_amount' => 0,
                'paid_amount' => 0,
                'notes' => filled($notes) ? trim($notes) : null,
                'created_by' => $by->id,
            ]);

            $this->refresh($invoice);
            $invoice->save();

            $invoice->items()->createMany(array_map(fn (array $line) => [
                'fee_structure_id' => $line['fee_structure_id'],
                'description' => mb_substr($line['description'], 0, 255),
                'amount' => $line['amount'],
                'discount_amount' => $line['discount_amount'],
                'net_amount' => $line['net_amount'],
                'discount_breakdown' => $line['discount_breakdown'],
            ], $lines));

            if (! $batch) {
                activity('invoice')->performedOn($invoice)->causedBy($by)
                    ->withProperties(['student' => $student->user?->name, 'total' => (float) $invoice->total_amount])
                    ->log('Invoice raised');
            }

            return $invoice;
        });
    }

    /**
     * Recompute the total and status (not saved).
     */
    public function refresh(Invoice $invoice, ?CarbonInterface $today = null): Invoice
    {
        $total = FeeCalculator::cents($invoice->subtotal) - FeeCalculator::cents($invoice->discount_amount) + FeeCalculator::cents($invoice->late_fee_amount);
        $invoice->total_amount = max($total, 0) / 100;

        if ($invoice->status === InvoiceStatus::Cancelled) {
            return $invoice;
        }

        $today ??= tenant()->today();
        $owed = FeeCalculator::cents($invoice->total_amount) - FeeCalculator::cents($invoice->paid_amount);

        $invoice->status = match (true) {
            $owed <= 0 => InvoiceStatus::Paid,
            $invoice->due_date->copy()->startOfDay()->lt($today->copy()->startOfDay()) => InvoiceStatus::Overdue,
            FeeCalculator::cents($invoice->paid_amount) > 0 => InvoiceStatus::Partial,
            default => InvoiceStatus::Unpaid,
        };

        return $invoice;
    }

    /**
     * @throws ActionFailed
     */
    public function updateDetails(Invoice $invoice, CarbonInterface $dueDate, ?string $notes, User $by): void
    {
        if ($invoice->isCancelled()) {
            throw new ActionFailed(__('A cancelled invoice can\'t be changed.'));
        }

        if ($dueDate->lt($invoice->issue_date)) {
            throw new ActionFailed(__('The due date can\'t be before the issue date.'));
        }

        $invoice->fill(['due_date' => $dueDate->toDateString(), 'notes' => filled($notes) ? trim($notes) : null]);
        $this->refresh($invoice);

        if (! $invoice->isDirty()) {
            return;
        }

        $changes = array_keys($invoice->getDirty());
        $invoice->save();

        activity('invoice')->performedOn($invoice)->causedBy($by)->withProperties(['changed' => $changes])->log('Invoice updated');
    }

    /**
     * Remove the late fee for good: it isn't charged again on this invoice.
     *
     * @throws ActionFailed
     */
    public function waiveLateFee(Invoice $invoice, User $by): void
    {
        if (! $invoice->isOpen() || (float) $invoice->late_fee_amount <= 0) {
            throw new ActionFailed(__('There is no late fee to waive.'));
        }

        $waived = (float) $invoice->late_fee_amount;

        $invoice->fill(['late_fee_amount' => 0, 'late_fee_waived_at' => now()]);
        $this->refresh($invoice);
        $invoice->save();

        activity('invoice')->performedOn($invoice)->causedBy($by)->withProperties(['amount' => $waived])->log('Late fee waived');
    }

    /**
     * @throws ActionFailed
     */
    public function cancel(Invoice $invoice, string $reason, User $by): void
    {
        if ($invoice->isCancelled()) {
            throw new ActionFailed(__('This invoice is already cancelled.'));
        }

        if ($invoice->payments()->valid()->exists()) {
            throw new ActionFailed(__('Payments have been received against this invoice. Void them before cancelling it.'));
        }

        $invoice->update([
            'status' => InvoiceStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => trim($reason),
        ]);

        activity('invoice')->performedOn($invoice)->causedBy($by)->withProperties(['reason' => trim($reason)])->log('Invoice cancelled');
    }
}
