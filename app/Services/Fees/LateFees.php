<?php

namespace App\Services\Fees;

use App\Enums\LateFeeType;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Carbon\CarbonInterface;

/**
 * Late-fee fines on unpaid invoices (Settings → Fee options).
 *
 * A fee with its own late-fee rule is fined on its own; everything else on
 * the invoice is fined once, under the school's rule. A fine is:
 *
 *  - fixed: the amount, once the grace days have passed;
 *  - daily: the amount for every day after the grace days;
 *  - percentage: that share of the fees it covers;
 *
 * capped at the rule's maximum. Nothing is charged while late fees are
 * switched off, on a waived invoice, or once the fees themselves are paid
 * (any fine already charged stays until it is paid or waived).
 */
class LateFees
{
    public function __construct(private readonly Invoices $invoices) {}

    public function amountFor(Invoice $invoice, CarbonInterface $today): float
    {
        $school = tenant();

        if (! $school->setting('fees.late_fee.enabled') || $invoice->late_fee_waived_at || $invoice->isCancelled()) {
            return 0.0;
        }

        $invoice->loadMissing(['items.feeStructure' => fn ($query) => $query->withTrashed()]);

        $default = [
            'type' => LateFeeType::tryFrom((string) $school->setting('fees.late_fee.type')) ?? LateFeeType::Fixed,
            'amount' => (float) $school->setting('fees.late_fee.amount'),
            'grace' => (int) $school->setting('fees.late_fee.grace_days'),
            'max' => $school->setting('fees.late_fee.max_amount'),
        ];

        $groups = $invoice->items->groupBy(fn (InvoiceItem $item) => $item->feeStructure?->late_fee_type ? 'fee-'.$item->fee_structure_id : 'school');
        $total = 0;

        foreach ($groups as $key => $items) {
            $rule = $key === 'school' ? $default : $this->ruleOf($items->first()->feeStructure, $default);
            $total += $this->fine($rule, FeeCalculator::cents($items->sum(fn (InvoiceItem $item) => (float) $item->net_amount)), $invoice, $today);
        }

        return $total / 100;
    }

    /**
     * Bring an open invoice's fine and status up to date.
     *
     * @return bool whether anything changed
     */
    public function apply(Invoice $invoice, ?CarbonInterface $today = null): bool
    {
        if (! $invoice->isOpen()) {
            return false;
        }

        $today ??= tenant()->today();
        $feesOutstanding = round((float) $invoice->subtotal - (float) $invoice->discount_amount - (float) $invoice->paid_amount, 2);

        if ($feesOutstanding > 0) {
            $invoice->late_fee_amount = $this->amountFor($invoice, $today);
        }

        $this->invoices->refresh($invoice, $today);

        if (! $invoice->isDirty()) {
            return false;
        }

        $invoice->save();

        return true;
    }

    /**
     * @param  array{type: LateFeeType, amount: float, grace: int, max: mixed}  $default
     * @return array{type: LateFeeType, amount: float, grace: int, max: mixed}
     */
    private function ruleOf(FeeStructure $fee, array $default): array
    {
        return [
            'type' => $fee->late_fee_type,
            'amount' => (float) $fee->late_fee_amount,
            'grace' => $fee->late_fee_grace_days ?? $default['grace'],
            'max' => $fee->late_fee_max,
        ];
    }

    /**
     * @param  array{type: LateFeeType, amount: float, grace: int, max: mixed}  $rule
     * @return int cents
     */
    private function fine(array $rule, int $base, Invoice $invoice, CarbonInterface $today): int
    {
        if ($rule['amount'] <= 0 || $base <= 0) {
            return 0;
        }

        $daysLate = (int) $invoice->due_date->copy()->startOfDay()->diffInDays($today->copy()->startOfDay(), false) - $rule['grace'];

        if ($daysLate < 1) {
            return 0;
        }

        $fine = match ($rule['type']) {
            LateFeeType::Daily => FeeCalculator::cents($rule['amount']) * $daysLate,
            LateFeeType::Percentage => (int) round($base * min($rule['amount'], 100) / 100),
            default => FeeCalculator::cents($rule['amount']),
        };

        $max = (float) $rule['max'];

        return $max > 0 ? min($fine, FeeCalculator::cents($max)) : $fine;
    }
}
