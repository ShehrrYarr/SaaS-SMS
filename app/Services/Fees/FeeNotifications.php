<?php

namespace App\Services\Fees;

use App\Enums\UserStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Notifications\SystemNotification;
use App\Support\Billing\Money;
use App\Support\SelfService;
use Illuminate\Support\Facades\DB;

/**
 * Tells parents about their child's fees in the bell: a new invoice, and a
 * payment received. Off when the school turns off fees.notify_parents.
 * Sent once the surrounding transaction commits, so a rolled-back invoice
 * never announces itself.
 */
class FeeNotifications
{
    public function invoiceRaised(Invoice $invoice): void
    {
        $this->afterCommit($invoice->student_profile_id, function (StudentProfile $student) use ($invoice) {
            $format = tenant()->setting('date_format');

            return new SystemNotification(
                __('New fee invoice for :name', ['name' => $student->user?->name]),
                __(':number · :amount due :date', [
                    'number' => $invoice->invoice_number,
                    'amount' => Money::school($invoice->total_amount),
                    'date' => $invoice->due_date->format($format),
                ]),
                SelfService::url('parent', 'fees', $student),
                'ri-bill-line',
                'warning',
            );
        });
    }

    public function paymentReceived(Payment $payment, Invoice $invoice): void
    {
        $this->afterCommit($invoice->student_profile_id, fn (StudentProfile $student) => new SystemNotification(
            __('Payment received for :name', ['name' => $student->user?->name]),
            __(':amount towards :number · receipt :receipt', [
                'amount' => Money::school($payment->amount_paid),
                'number' => $invoice->invoice_number,
                'receipt' => $payment->receipt_number,
            ]),
            SelfService::url('parent', 'fees', $student),
            'ri-hand-coin-line',
            'success',
        ));
    }

    /**
     * @param  callable(StudentProfile): SystemNotification  $build
     */
    private function afterCommit(int $studentId, callable $build): void
    {
        if (! tenant()?->setting('fees.notify_parents')) {
            return;
        }

        DB::afterCommit(function () use ($studentId, $build) {
            $student = StudentProfile::query()->with(['user', 'parents.user'])->find($studentId);
            $parents = $student?->parents->pluck('user')->filter(fn ($user) => $user?->status === UserStatus::Active);

            if ($student && $parents->isNotEmpty()) {
                $notification = $build($student);
                $parents->each->notify($notification);
            }
        });
    }
}
