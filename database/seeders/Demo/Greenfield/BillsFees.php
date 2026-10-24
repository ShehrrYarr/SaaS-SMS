<?php

namespace Database\Seeders\Demo\Greenfield;

use App\Enums\AdjustmentType;
use App\Enums\FeeFrequency;
use App\Enums\PaymentMethod;
use App\Models\FeeDiscount;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\InvoiceBatch;
use App\Models\Payment;
use App\Models\SiblingDiscountRule;
use App\Models\StudentFeeDiscount;
use App\Models\StudentProfile;
use App\Services\Fees\FeeCalculator;
use App\Services\Fees\FeeCollection;
use App\Services\Fees\FeeSchedule;
use App\Services\Fees\InvoiceBatches;
use App\Services\Fees\Invoices;
use App\Services\Fees\LateFees;
use App\Services\Fees\ServiceCharges;
use App\Tables\ActionFailed;
use Carbon\CarbonImmutable;

/**
 * Fees: the fee list, discounts and sibling rules, admission fees, a bulk
 * run on the first school day of every month (bus fares and hostel rent
 * included), and families paying the way families do: most on time, some
 * late with a late fee, some in parts, a few not yet. One cheque bounces;
 * one invoice raised by mistake is cancelled.
 */
trait BillsFees
{
    /** Monthly tuition by class. */
    private const TUITION = ['PREP' => 9500, 'G1' => 10000, 'G2' => 10000, 'G3' => 11500, 'G4' => 11500, 'G5' => 11500, 'G6' => 13000, 'G7' => 13000, 'G8' => 14000];

    private FeeStructure $admissionFee;

    /** @var array<string, FeeDiscount> */
    private array $discounts = [];

    private bool $chequeBounced = false;

    private function setUpFees(): void
    {
        $this->as($this->people['omar'], function () {
            foreach (self::TUITION as $class => $amount) {
                FeeStructure::query()->create([
                    'academic_year_id' => $this->year->id,
                    'class_id' => $this->classes[$class]->id,
                    'title' => 'Monthly Tuition',
                    'description' => 'Tuition for '.self::CLASSES[$class][0].'.',
                    'amount' => $amount,
                    'frequency' => FeeFrequency::Monthly,
                    'due_day' => 10,
                    'allow_discounts' => true,
                    'allow_sibling_discount' => true,
                    'is_active' => true,
                ]);
            }

            FeeStructure::query()->create([
                'academic_year_id' => $this->year->id,
                'title' => 'Annual Charges',
                'description' => 'Books, stationery, lab and sports for the year.',
                'amount' => 12000,
                'frequency' => FeeFrequency::Yearly,
                'due_day' => 10,
                'allow_discounts' => false,
                'allow_sibling_discount' => false,
                'is_active' => true,
            ]);

            FeeStructure::query()->create([
                'academic_year_id' => $this->year->id,
                'title' => 'Examination Fee',
                'description' => 'Charged each term.',
                'amount' => 1500,
                'frequency' => FeeFrequency::HalfYearly,
                'due_day' => 10,
                'allow_discounts' => false,
                'allow_sibling_discount' => false,
                'is_active' => true,
            ]);

            $this->admissionFee = FeeStructure::query()->create([
                'title' => 'Admission Fee',
                'description' => 'Once, when a student joins.',
                'amount' => 15000,
                'frequency' => FeeFrequency::OneTime,
                'allow_discounts' => false,
                'allow_sibling_discount' => false,
                'is_active' => true,
            ]);

            $this->discounts['staff'] = FeeDiscount::query()->create(['name' => 'Staff Child', 'code' => 'STAFF', 'type' => AdjustmentType::Percentage, 'value' => 50, 'description' => 'For children of Greenfield staff.', 'is_active' => true]);
            $this->discounts['merit'] = FeeDiscount::query()->create(['name' => 'Merit Scholarship', 'code' => 'MERIT', 'type' => AdjustmentType::Percentage, 'value' => 25, 'description' => 'Top results in last year\'s final examination.', 'is_active' => true]);
            $this->discounts['need'] = FeeDiscount::query()->create(['name' => 'Need-based Concession', 'code' => 'NEED', 'type' => AdjustmentType::Fixed, 'value' => 2000, 'description' => 'Approved by the principal after a family review.', 'is_active' => true]);

            SiblingDiscountRule::query()->create(['child_position' => 2, 'type' => AdjustmentType::Percentage, 'value' => 10, 'is_active' => true]);
            SiblingDiscountRule::query()->create(['child_position' => 3, 'type' => AdjustmentType::Percentage, 'value' => 15, 'is_active' => true]);
        });
    }

    /**
     * Discounts given before term: the staff child, last year's toppers and
     * two families on a concession.
     */
    private function giveDiscounts(): void
    {
        $given = [];
        $merit = array_filter($this->pupils, fn (array $pupil) => in_array(substr($pupil['section'], 0, 2), ['G5', 'G6', 'G7', 'G8'], true) && ! $pupil['staff_child']);
        uasort($merit, fn (array $a, array $b) => $b['ability'] <=> $a['ability']);

        foreach ($this->pupils as $id => $pupil) {
            if ($pupil['staff_child']) {
                $given[$id] = ['staff', 'Son of Ms Maryam Iqbal (Head of Science).'];
            }
        }

        foreach (array_slice(array_keys($merit), 0, $this->scaled(3)) as $id) {
            $given[$id] = ['merit', 'First position in last year\'s final examination.'];
        }

        $others = array_values(array_filter(array_keys($this->pupils), fn (int $id) => ! isset($given[$id]) && $this->pupils[$id]['family'] > 1));

        foreach ($this->dice->sample($others, $this->scaled(2)) as $id) {
            $given[$id] = ['need', null];
        }

        foreach ($given as $id => [$discount, $remarks]) {
            $row = StudentFeeDiscount::query()->create([
                'student_profile_id' => $id,
                'fee_discount_id' => $this->discounts[$discount]->id,
                'valid_from' => $this->yearStart->toDateString(),
                'valid_until' => $this->yearEnd->toDateString(),
                'remarks' => $remarks,
                'approved_by' => $this->admin->id,
            ]);

            activity('student_fee_discount')->performedOn($row)->causedBy($this->admin)
                ->withProperties(['student' => $this->pupils[$id]['student']->user?->name, 'discount' => $this->discounts[$discount]->name])
                ->log('Discount given to student');
        }
    }

    private function chargeAdmissionFee(StudentProfile $student, int $family): void
    {
        $accountant = $this->people['omar'];

        $this->as($accountant, function () use ($student, $family, $accountant) {
            $today = tenant()->today();
            $invoice = app(Invoices::class)->create($student, [app(FeeCalculator::class)->line($student, $this->admissionFee, $today)],
                $today, $today->copy()->addDays(7), null, $accountant, 'New admission.');

            $this->pay($invoice, $this->families[$family]['method']);
        });
    }

    /**
     * The bulk run on the first school day of each month, then each
     * family's payments for it.
     */
    private function planInvoices(): void
    {
        for ($month = $this->yearStart; $month->lte($this->today); $month = $month->addMonthNoOverflow()) {
            $runAt = $this->on($this->schoolDay($month), '09:30');
            $index = (int) $this->yearStart->diffInMonths($month);
            $latest = $month->format('Y-m') === $this->today->format('Y-m');

            $this->at($runAt, fn () => $this->runMonth($month, $index, $latest));
        }

        // An invoice raised by mistake in the second month, cancelled the same afternoon.
        $mistake = $this->on($this->addSchoolDays($this->yearStart->addMonthNoOverflow(), 4), '11:15');

        $this->at($mistake, function () use ($mistake) {
            $student = $this->pupils[array_key_first(array_filter($this->pupils, fn (array $pupil) => str_starts_with($pupil['section'], 'G6')))]['student'] ?? null;
            $accountant = $this->people['omar'];

            if (! $student) {
                return;
            }

            $invoice = $this->as($accountant, fn () => app(Invoices::class)->create($student, [FeeCalculator::customLine('Educational trip: Lahore Museum', 1500)],
                tenant()->today(), tenant()->today()->addDays(10), null, $accountant, 'Grade 6 trip.'));

            $this->at($mistake->setTime(15, 40), fn () => $this->as($accountant, fn () => app(Invoices::class)->cancel($invoice->refresh(), 'Trip postponed to next term; raised too early.', $accountant)));
        });
    }

    private function runMonth(CarbonImmutable $month, int $index, bool $latest): void
    {
        $accountant = $this->people['omar'];
        $period = $month->format('Y-m');
        $fees = app(FeeSchedule::class)->dueIn($period)->reject(fn (FeeStructure $fee) => $fee->is($this->admissionFee));

        $batch = $this->as($accountant, fn () => app(InvoiceBatches::class)->start([
            'title' => FeeSchedule::periodLabel($period).' fees',
            'billing_period' => $period,
            'class_ids' => [],
            'fee_structure_ids' => $fees->modelKeys(),
            'services' => ServiceCharges::available(),
            'issue_date' => $month->toDateString(),
            'due_date' => $month->setDay(10)->toDateString(),
        ], $accountant));

        $invoices = Invoice::query()->where('invoice_batch_id', $batch->id)->get();
        $due = $month->setDay(10);

        foreach ($invoices as $invoice) {
            $pupil = $this->pupils[$invoice->student_profile_id] ?? null;

            if ($pupil) {
                $this->planPayments($invoice, $this->families[$pupil['family']], $month, $due, $index, $latest);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $family
     */
    private function planPayments(Invoice $invoice, array $family, CarbonImmutable $issued, CarbonImmutable $due, int $index, bool $latest): void
    {
        $method = $family['method'];
        $months = (int) $issued->diffInMonths($this->today->startOfMonth());
        $pay = fn (CarbonImmutable $day, ?float $amount = null) => $this->at($this->paymentTime($day, $method), fn () => $this->pay($invoice, $method, $amount, $index));

        match ($family['payer']) {
            'persona' => match (true) {
                $latest => null,
                $index === 0 => $pay($issued->addDays(4)),
                default => $pay($due->addDays(7)),
            },
            'late' => $pay($due->addDays($this->dice->int(4, 18))),
            'partial' => [
                $pay($issued->addDays($this->dice->int(2, 9)), floor((float) $invoice->total_amount / 2 / 500) * 500),
                $months >= 1 ? $pay($due->addDays($this->dice->int(10, 25))) : null,
            ],
            'defaulter' => match (true) {
                $months >= 2 => $pay($due->addDays($this->dice->int(15, 30))),
                $months === 1 && $this->dice->chance(50) => $pay($due->addDays($this->dice->int(20, 35))),
                default => null,
            },
            default => $pay($issued->addDays($this->dice->int(1, 8))),
        };
    }

    /**
     * Cash and cheques come in at the office on school days; transfers any day.
     */
    private function paymentTime(CarbonImmutable $day, PaymentMethod $method): CarbonImmutable
    {
        if (in_array($method, [PaymentMethod::Cash, PaymentMethod::Cheque], true)) {
            $day = $this->schoolDay($day);
        }

        return $this->on($day, sprintf('%02d:%02d', $this->dice->int(9, 14), $this->dice->int(0, 59)));
    }

    /**
     * Take a payment on an invoice: the whole balance (late fee included)
     * unless an amount is given.
     */
    private function pay(Invoice $invoice, PaymentMethod $method, ?float $amount = null, int $month = 0): ?Payment
    {
        $accountant = $this->people['omar'];
        $invoice = $invoice->refresh();

        if (! $invoice->isOpen()) {
            return null;
        }

        app(LateFees::class)->apply($invoice);
        $balance = round((float) $invoice->total_amount - (float) $invoice->paid_amount, 2);

        if ($balance <= 0) {
            return null;
        }

        $reference = match ($method) {
            PaymentMethod::BankTransfer => 'IBFT-'.$this->dice->digits(10),
            PaymentMethod::Online => $this->dice->pick(['JazzCash', 'Easypaisa']).' '.$this->dice->digits(11),
            PaymentMethod::Cheque => 'CHQ '.$this->dice->digits(8),
            default => null,
        };

        try {
            $payment = $this->as($accountant, fn () => app(FeeCollection::class)->collect(
                $invoice, $amount === null ? $balance : min($amount, $balance), $method, tenant()->today(), $accountant, $reference,
                $method === PaymentMethod::Cheque ? $this->dice->pick(['Meezan Bank', 'HBL', 'UBL']).' cheque' : null,
            ));
        } catch (ActionFailed) {
            return null;
        }

        // One cheque in the second month bounces; the family pays cash a week later.
        if ($method === PaymentMethod::Cheque && $month === 1 && ! $this->chequeBounced) {
            $this->chequeBounced = true;
            $returned = $this->addSchoolDays(tenant()->today(), 3);

            $this->at($this->on($returned, '12:10'), fn () => $this->as($accountant, fn () => app(FeeCollection::class)->void($payment->refresh(), 'Cheque returned unpaid by the bank (insufficient funds).', $accountant)));
            $this->at($this->on($this->addSchoolDays($returned, 5), '10:40'), fn () => $this->pay($invoice, PaymentMethod::Cash));
        }

        return $payment;
    }
}
