<?php

namespace App\Services\Fees;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\Ledger;
use App\Services\Numbering\Sequences;
use App\Support\Billing\Money;
use App\Tables\ActionFailed;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Taking fee payments at the counter and voiding mistaken ones.
 *
 * Every payment gets its own receipt number (RCT-2026-0001), and is posted
 * to the income ledger. Payments are never edited: a mistake is voided with
 * a reason, which reopens the invoice and removes the income.
 */
class FeeCollection
{
    public function __construct(
        private readonly Invoices $invoices,
        private readonly LateFees $lateFees,
        private readonly Sequences $sequences,
        private readonly Ledger $ledger,
        private readonly FeeNotifications $notifications,
    ) {}

    public function receiptNumber(CarbonInterface $date): string
    {
        $prefix = tenant()->setting('numbering.receipt_prefix').$date->year.'-';

        return $this->sequences->next('receipt-'.$date->year, $prefix,
            fn (string $number) => Payment::query()->withTrashed()->where('receipt_number', $number)->exists());
    }

    /**
     * @throws ActionFailed
     */
    public function collect(
        Invoice $invoice,
        float|string $amount,
        PaymentMethod $method,
        CarbonInterface $date,
        User $by,
        ?string $reference = null,
        ?string $notes = null,
    ): Payment {
        return DB::transaction(function () use ($invoice, $amount, $method, $date, $by, $reference, $notes) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->isCancelled()) {
                throw new ActionFailed(__('This invoice is cancelled.'));
            }

            // Charge any late fee due so far before taking the money.
            $this->lateFees->apply($invoice);

            $cents = FeeCalculator::cents($amount);
            $balance = FeeCalculator::cents($invoice->total_amount) - FeeCalculator::cents($invoice->paid_amount);

            if ($balance <= 0) {
                throw new ActionFailed(__('This invoice is already paid.'));
            }

            if ($cents <= 0) {
                throw new ActionFailed(__('Enter the amount received.'));
            }

            if ($cents > $balance) {
                throw new ActionFailed(__('Only :balance is owed on this invoice.', ['balance' => Money::school($balance / 100)]));
            }

            $payment = $invoice->payments()->create([
                'receipt_number' => $this->receiptNumber($date),
                'amount_paid' => $cents / 100,
                'payment_method' => $method,
                'transaction_id' => filled($reference) ? trim($reference) : null,
                'payment_date' => $date->toDateString(),
                'notes' => filled($notes) ? trim($notes) : null,
                'received_by' => $by->id,
            ]);

            $invoice->paid_amount = (FeeCalculator::cents($invoice->paid_amount) + $cents) / 100;
            $this->invoices->refresh($invoice);
            $invoice->save();

            $this->ledger->postFeePayment($payment->setRelation('invoice', $invoice));

            activity('payment')->performedOn($payment)->causedBy($by)
                ->withProperties([
                    'invoice' => $invoice->invoice_number,
                    'student' => $invoice->student?->user?->name,
                    'amount' => $cents / 100,
                    'method' => $method->value,
                ])
                ->log('Fee received');

            $this->notifications->paymentReceived($payment, $invoice);

            return $payment;
        });
    }

    /**
     * @throws ActionFailed
     */
    public function void(Payment $payment, string $reason, User $by): void
    {
        DB::transaction(function () use ($payment, $reason, $by) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->isVoided()) {
                throw new ActionFailed(__('This receipt is already void.'));
            }

            $payment->update([
                'voided_at' => now(),
                'voided_by' => $by->id,
                'void_reason' => trim($reason),
            ]);

            $invoice->paid_amount = max(FeeCalculator::cents($invoice->paid_amount) - FeeCalculator::cents($payment->amount_paid), 0) / 100;
            $this->invoices->refresh($invoice);
            $invoice->save();

            $this->ledger->reverseFeePayment($payment);

            activity('payment')->performedOn($payment)->causedBy($by)
                ->withProperties(['invoice' => $invoice->invoice_number, 'amount' => (float) $payment->amount_paid, 'reason' => trim($reason)])
                ->log('Receipt voided');
        });
    }
}
