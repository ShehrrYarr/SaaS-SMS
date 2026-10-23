<?php

use App\Enums\AdjustmentType;
use App\Enums\BatchStatus;
use App\Enums\FeeFrequency;
use App\Enums\InvoiceStatus;
use App\Enums\LateFeeType;
use App\Enums\PaymentMethod;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\FeeDiscount;
use App\Models\FeeStructure;
use App\Models\FinanceCategory;
use App\Models\IncomeLedger;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SchoolClass;
use App\Models\SiblingDiscountRule;
use App\Models\StudentFeeDiscount;
use App\Services\Fees\FeeCalculator;
use App\Services\Fees\FeeCollection;
use App\Services\Fees\FeeSchedule;
use App\Services\Fees\InvoiceBatches;
use App\Services\Fees\Invoices;
use App\Services\Fees\LateFees;
use App\Services\Schools\SeedSchoolDefaults;
use App\Services\Students\StudentRecords;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    app(SeedSchoolDefaults::class)->handle($this->school);
    tenancy()->set($this->school);

    $this->accountant = makeUserWithRole($this->school, UserType::Accountant, 'Accountant');

    $this->year = AcademicYear::factory()->current()->create(['start_date' => '2026-08-01', 'end_date' => '2027-06-30']);
    $campus = Campus::query()->create(['name' => 'Main', 'is_main' => true]);
    $this->class = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 3', 'numeric_level' => 3]);
    $this->other = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 4', 'numeric_level' => 4]);

    $records = app(StudentRecords::class);
    // Two siblings (same guardian phone): Ali is the elder.
    $this->ali = $records->admit(['name' => 'Ali', 'class_id' => $this->class->id, 'dob' => '2016-01-01'], [['name' => 'Mr Raza', 'phone' => '0300111', 'relation' => 'father']])->student;
    $this->sara = $records->admit(['name' => 'Sara', 'class_id' => $this->class->id, 'dob' => '2018-05-05'], [['name' => 'Mr Raza', 'phone' => '0300111', 'relation' => 'father']])->student;
    $this->omar = $records->admit(['name' => 'Omar', 'class_id' => $this->other->id])->student;

    $this->tuition = FeeStructure::query()->create(['title' => 'Tuition', 'amount' => 1000, 'frequency' => FeeFrequency::Monthly, 'due_day' => 10, 'academic_year_id' => $this->year->id]);
    $this->exam = FeeStructure::query()->create(['title' => 'Exam fee', 'amount' => 300, 'frequency' => FeeFrequency::Quarterly, 'due_day' => 15, 'class_id' => $this->class->id]);
    $this->admission = FeeStructure::query()->create(['title' => 'Admission', 'amount' => 5000, 'frequency' => FeeFrequency::OneTime, 'allow_discounts' => false, 'allow_sibling_discount' => false]);
    // Pick up the database defaults (allow_discounts, is_active...).
    foreach (['tuition', 'exam', 'admission'] as $fee) {
        $this->{$fee}->refresh();
    }

    Carbon::setTestNow('2026-10-05 09:00:00');
    $this->actingAs($this->accountant);
});

afterEach(fn () => Carbon::setTestNow());

function setFees(array $settings): void
{
    $school = tenant();
    $school->update(['settings' => array_replace_recursive($school->settings ?? [], ['fees' => $settings])]);
    tenancy()->set($school->fresh());
}

function raise(App\Models\StudentProfile $student, array $fees, ?string $period = '2026-10', string $due = '2026-10-10'): Invoice
{
    $invoices = app(Invoices::class);
    $on = $period ? FeeSchedule::periodStart($period) : today();

    $due = Carbon::parse($due);

    return $invoices->create($student, $invoices->linesFor($student, collect($fees), $period, $on), today()->min($due), $due, $period, test()->accountant);
}

it('knows which fees fall due in a month', function () {
    $schedule = app(FeeSchedule::class);

    // The year starts in August: quarterly fees fall in Aug, Nov, Feb, May.
    expect($schedule->isDue($this->tuition, '2026-10', $this->year))->toBeTrue()
        ->and($schedule->isDue($this->exam, '2026-11', $this->year))->toBeTrue()
        ->and($schedule->isDue($this->exam, '2026-10', $this->year))->toBeFalse()
        ->and($schedule->isDue($this->tuition, '2027-07', $this->year))->toBeFalse()
        ->and($schedule->dueIn('2026-11')->pluck('title')->all())->toContain('Tuition', 'Exam fee', 'Admission')
        ->and($schedule->dueDateFor('2026-11', collect([$this->tuition, $this->exam]))->toDateString())->toBe('2026-11-10');
});

it('applies discounts and the sibling discount, capped at the fee', function () {
    setFees(['sibling_discount_enabled' => true]);

    $merit = FeeDiscount::query()->create(['name' => 'Merit', 'type' => AdjustmentType::Percentage, 'value' => 25]);
    $bursary = FeeDiscount::query()->create(['name' => 'Bursary', 'type' => AdjustmentType::Fixed, 'value' => 900, 'fee_structure_id' => $this->tuition->id]);
    StudentFeeDiscount::query()->create(['student_profile_id' => $this->sara->id, 'fee_discount_id' => $merit->id]);
    SiblingDiscountRule::query()->create(['child_position' => 2, 'type' => AdjustmentType::Percentage, 'value' => 10]);

    $calculator = app(FeeCalculator::class);
    $on = Carbon::parse('2026-10-01');

    expect($calculator->childPosition($this->ali))->toBe(1)
        ->and($calculator->childPosition($this->sara))->toBe(2)
        ->and($calculator->line($this->ali, $this->tuition, $on)['net_amount'])->toBe(1000.0);

    $line = $calculator->line($this->sara, $this->tuition, $on, '2026-10');
    expect($line['description'])->toBe('Tuition — October 2026')
        ->and($line['discount_amount'])->toBe(350.0) // 25% + 10%
        ->and(collect($line['discount_breakdown'])->pluck('amount')->all())->toBe([250.0, 100.0]);

    // The admission fee doesn't allow reductions.
    expect(app(FeeCalculator::class)->line($this->sara, $this->admission, $on)['discount_amount'])->toBe(0.0);

    // A big fixed bursary on top can't take the fee below zero.
    StudentFeeDiscount::query()->create(['student_profile_id' => $this->sara->id, 'fee_discount_id' => $bursary->id]);
    $capped = app(FeeCalculator::class)->line($this->sara, $this->tuition, $on);
    expect($capped['net_amount'])->toBe(0.0)
        ->and(array_sum(array_column($capped['discount_breakdown'], 'amount')))->toBe(1000.0);

    // Switched off in settings: no reductions at all.
    setFees(['discounts_enabled' => false, 'sibling_discount_enabled' => false]);
    expect(app(FeeCalculator::class)->line($this->sara, $this->tuition, $on)['discount_amount'])->toBe(0.0);
});

it('only counts discounts valid on the billing date', function () {
    $merit = FeeDiscount::query()->create(['name' => 'Merit', 'type' => AdjustmentType::Percentage, 'value' => 50]);
    StudentFeeDiscount::query()->create(['student_profile_id' => $this->ali->id, 'fee_discount_id' => $merit->id, 'valid_from' => '2026-11-01']);

    $calculator = app(FeeCalculator::class);

    expect($calculator->line($this->ali, $this->tuition, Carbon::parse('2026-10-01'))['discount_amount'])->toBe(0.0)
        ->and($calculator->line($this->ali, $this->tuition, Carbon::parse('2026-11-01'))['discount_amount'])->toBe(500.0);
});

it('numbers invoices per year and never bills a fee twice for a period', function () {
    $first = raise($this->ali, [$this->tuition, $this->admission]);

    expect($first->invoice_number)->toBe('INV-2026-0001')
        ->and((float) $first->total_amount)->toBe(6000.0)
        ->and($first->status)->toBe(InvoiceStatus::Unpaid)
        ->and($first->items)->toHaveCount(2);

    $invoices = app(Invoices::class);
    expect($invoices->linesFor($this->ali, collect([$this->tuition, $this->admission]), '2026-10', today()))->toBe([])
        ->and($invoices->linesFor($this->ali, collect([$this->tuition, $this->admission]), '2026-11', today()))->toHaveCount(1)
        // Omar's class doesn't have the Grade 3 exam fee.
        ->and($invoices->linesFor($this->omar, collect([$this->exam]), '2026-11', today()))->toBe([]);

    expect(raise($this->sara, [$this->tuition])->invoice_number)->toBe('INV-2026-0002');

    // Cancelling frees the fee to be billed again.
    $invoices->cancel($first, 'Wrong amount', $this->accountant);
    expect($invoices->linesFor($this->ali, collect([$this->tuition]), '2026-10', today()))->toHaveCount(1)
        ->and($first->fresh()->status)->toBe(InvoiceStatus::Cancelled);
});

it('collects payments with receipts, posts income, and voids them', function () {
    $invoice = raise($this->ali, [$this->tuition]);
    $collection = app(FeeCollection::class);

    $part = $collection->collect($invoice, 400, PaymentMethod::Cash, today(), $this->accountant);
    expect($part->receipt_number)->toBe('RCT-2026-0001')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Partial)
        ->and((float) $invoice->fresh()->paid_amount)->toBe(400.0);

    expect(fn () => $collection->collect($invoice, 700, PaymentMethod::Cash, today(), $this->accountant))
        ->toThrow(ActionFailed::class, 'Only $600.00 is owed');

    $rest = $collection->collect($invoice, 600, PaymentMethod::BankTransfer, today(), $this->accountant, 'TRX-9');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(IncomeLedger::query()->sum('amount'))->toEqual(1000)
        ->and(IncomeLedger::query()->first()->category->system_key)->toBe(FinanceCategory::FEES);

    expect(fn () => app(Invoices::class)->cancel($invoice->fresh(), 'x', $this->accountant))->toThrow(ActionFailed::class);

    $collection->void($rest, 'Cheque bounced', $this->accountant);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Partial)
        ->and((float) $invoice->fresh()->paid_amount)->toBe(400.0)
        ->and($rest->fresh()->isVoided())->toBeTrue()
        ->and(IncomeLedger::query()->sum('amount'))->toEqual(400);

    expect(fn () => $collection->void($rest->fresh(), 'again', $this->accountant))->toThrow(ActionFailed::class);
});

it('charges late fees after the grace days, per the school or the fee\'s own rule', function () {
    setFees(['late_fee' => ['enabled' => true, 'type' => 'daily', 'amount' => 10, 'grace_days' => 2, 'max_amount' => 50]]);

    $invoice = raise($this->ali, [$this->tuition, $this->admission], due: '2026-10-01');
    $lateFees = app(LateFees::class);

    expect($lateFees->amountFor($invoice, Carbon::parse('2026-10-03')))->toBe(0.0)
        ->and($lateFees->amountFor($invoice, Carbon::parse('2026-10-06')))->toBe(30.0)
        ->and($lateFees->amountFor($invoice, Carbon::parse('2026-11-30')))->toBe(50.0);

    // The admission fee has its own 5% rule and no grace: fined separately.
    $this->admission->update(['late_fee_type' => LateFeeType::Percentage, 'late_fee_amount' => 5, 'late_fee_grace_days' => 0]);
    $invoice->unsetRelation('items');
    expect($lateFees->amountFor($invoice, Carbon::parse('2026-10-06')))->toBe(30.0 + 250.0);

    // Applying marks it overdue and adds the fine to the total.
    $lateFees->apply($invoice, Carbon::parse('2026-10-06'));
    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::Overdue)
        ->and((float) $invoice->late_fee_amount)->toBe(280.0)
        ->and((float) $invoice->total_amount)->toBe(6280.0);

    // Waived: gone for good.
    app(Invoices::class)->waiveLateFee($invoice, $this->accountant);
    $lateFees->apply($invoice->refresh(), Carbon::parse('2026-10-20'));
    expect((float) $invoice->fresh()->late_fee_amount)->toBe(0.0)
        ->and((float) $invoice->fresh()->total_amount)->toBe(6000.0);
});

it('marks overdue invoices and charges late fees nightly', function () {
    $invoice = raise($this->ali, [$this->tuition], due: '2026-10-01');
    $paid = raise($this->sara, [$this->tuition], due: '2026-10-01');
    app(FeeCollection::class)->collect($paid, 1000, PaymentMethod::Cash, today(), $this->accountant);
    setFees(['late_fee' => ['enabled' => true, 'type' => 'fixed', 'amount' => 75, 'grace_days' => 0, 'max_amount' => null]]);

    tenancy()->forget();
    $this->artisan('fees:apply-late-fees')->assertSuccessful();
    tenancy()->set($this->school);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Overdue)
        ->and((float) $invoice->fresh()->late_fee_amount)->toBe(75.0)
        ->and($paid->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and((float) $paid->fresh()->late_fee_amount)->toBe(0.0);
});

it('generates a month\'s invoices for whole classes on the queue', function () {
    raise($this->ali, [$this->tuition], period: '2026-11', due: '2026-11-10');

    $batch = app(InvoiceBatches::class)->start([
        'title' => 'November fees',
        'billing_period' => '2026-11',
        'class_ids' => [$this->class->id],
        'fee_structure_ids' => [$this->tuition->id, $this->exam->id],
        'issue_date' => '2026-10-25',
        'due_date' => '2026-11-10',
    ], $this->accountant);

    $batch->refresh();
    expect($batch->status)->toBe(BatchStatus::Completed)
        ->and($batch->total_students)->toBe(2)
        ->and($batch->invoices_created)->toBe(2)
        ->and($batch->processed)->toBe(2);

    // Ali already had November tuition: only the exam fee is billed to him.
    $aliInvoice = Invoice::query()->where('invoice_batch_id', $batch->id)->where('student_profile_id', $this->ali->id)->sole();
    expect($aliInvoice->items->pluck('fee_structure_id')->all())->toBe([$this->exam->id])
        ->and(Invoice::query()->where('student_profile_id', $this->omar->id)->exists())->toBeFalse();

    // Running it again bills nobody twice.
    $again = app(InvoiceBatches::class)->start([...$batch->filters, 'title' => 'Again', 'billing_period' => '2026-11', 'class_ids' => [], 'fee_structure_ids' => [$this->tuition->id, $this->exam->id]], $this->accountant);
    expect($again->refresh()->invoices_created)->toBe(1) // only Omar's tuition
        ->and($again->skipped)->toBe(2);

    setFees(['bulk_generation_enabled' => false]);
    expect(fn () => app(InvoiceBatches::class)->start([...$batch->filters, 'title' => 'x', 'billing_period' => '2026-12', 'class_ids' => [], 'fee_structure_ids' => [$this->tuition->id]], $this->accountant))
        ->toThrow(ActionFailed::class);
});

it('raises an invoice and takes a payment through the forms', function () {
    $component = Volt::test('forms.invoice')
        ->call('pickStudent', $this->sara->id)
        ->assertSet('fees.'.$this->tuition->id, true)
        ->assertSet('fees.'.$this->exam->id, false)   // not due in October
        ->set('custom', [['description' => 'Trip', 'amount' => '150']])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $invoice = Invoice::query()->where('student_profile_id', $this->sara->id)->sole();
    // Tuition, the admission fee (one-time with no date, so due whenever) and the trip.
    expect((float) $invoice->total_amount)->toBe(6150.0)
        ->and($invoice->billing_period)->toBe('2026-10')
        ->and($invoice->due_date->toDateString())->toBe('2026-10-10');

    Volt::test('forms.payment', ['invoice' => $invoice->id])
        ->assertSet('amount', App\Support\Billing\Money::input($invoice->total_amount))
        ->set('amount', '100')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('payment-recorded');

    expect(Payment::query()->where('invoice_id', $invoice->id)->sum('amount_paid'))->toEqual(100);
});

it('shows the accounting screens and documents', function () {
    $invoice = raise($this->ali, [$this->tuition]);
    $payment = app(FeeCollection::class)->collect($invoice, 500, PaymentMethod::Cash, today(), $this->accountant);

    foreach ([
        'accounting.dashboard', 'accounting.collect', 'accounting.invoices.index', 'accounting.invoice-batches.index', 'accounting.payments.index',
        'accounting.fee-structures.index', 'accounting.discounts.index', 'accounting.expenses.index', 'accounting.income.index',
        'accounting.categories.index', 'accounting.reports.index',
    ] as $route) {
        $this->get(route($route))->assertOk();
    }

    $this->get(route('accounting.invoices.show', $invoice->id))->assertOk()->assertSee($invoice->invoice_number);
    $this->get(route('accounting.invoices.document', $invoice->id))->assertOk()->assertSee('Tuition — October 2026');
    $this->get(route('accounting.receipts.document', $payment->id))->assertOk()->assertSee($payment->receipt_number);
    $this->get(route('accounting.receipts.pdf', $payment->id))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('accounting.collect', ['for' => $this->ali->id]))->assertOk()->assertSee($invoice->invoice_number);
});

it('records expenses with a proof file, and protects automatic entries and categories', function () {
    Illuminate\Support\Facades\Storage::fake('local');
    $utilities = FinanceCategory::query()->where('name', 'Utilities')->sole();

    Volt::test('forms.ledger-entry', ['type' => 'expense'])
        ->set('title', 'Electricity bill')
        ->set('finance_category_id', (string) $utilities->id)
        ->set('amount', '850')
        ->set('proof', Illuminate\Http\UploadedFile::fake()->create('bill.pdf', 120, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors();

    $expense = App\Models\Expense::query()->sole();
    expect((float) $expense->amount)->toBe(850.0)
        ->and($expense->recorded_by)->toBe($this->accountant->id);
    Illuminate\Support\Facades\Storage::disk('local')->assertExists($expense->proof_receipt);
    $this->get(route('accounting.ledger.proof', ['type' => 'expense', 'entry' => $expense->id]))->assertOk();

    // A fee receipt's income entry can't be edited by hand.
    $invoice = raise($this->ali, [$this->tuition]);
    app(FeeCollection::class)->collect($invoice, 1000, PaymentMethod::Cash, today(), $this->accountant);
    $income = IncomeLedger::query()->sole();
    Volt::test('forms.ledger-entry', ['type' => 'income', 'entry' => $income->id])->assertNotFound();

    $table = (new App\Tables\FinanceCategoriesTable);
    $fees = FinanceCategory::query()->where('system_key', FinanceCategory::FEES)->sole();
    expect($table->action('delete')->isAllowed($this->accountant, $fees))->toBeFalse()
        ->and($table->action('delete')->isAllowed($this->accountant, $utilities))->toBeTrue();
});

it('shows a student\'s invoices on their profile', function () {
    $invoice = raise($this->ali, [$this->tuition]);
    $admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');

    $this->actingAs($admin)->get(route('admin.students.show', $this->ali->id))->assertOk()->assertSee('Fees');

    Volt::actingAs($admin)->test('tenant.admin.students.show', ['student' => $this->ali->id])
        ->set('tab', 'fees')
        ->assertSee($invoice->invoice_number)
        ->set('tab', 'leave')
        ->assertSee('No leave requests.');
});

it('keeps fees inside the school', function () {
    $invoice = raise($this->ali, [$this->tuition]);

    $other = makeSchool(['slug' => 'beta']);
    seedSchoolRoles($other);
    $outsider = makeUserWithRole($other, UserType::Accountant, 'Accountant');

    tenancy()->set($other);
    expect(Invoice::query()->find($invoice->id))->toBeNull();

    $this->actingAs($outsider)->get(route('accounting.invoices.show', ['school' => 'beta', 'invoice' => $invoice->id]))->assertNotFound();
    $this->actingAs($outsider)->get(route('accounting.receipts.document', ['school' => 'beta', 'payment' => 999]))->assertNotFound();
});

it('lets only the right roles into accounting', function () {
    $teacher = makeUserWithRole($this->school, UserType::Teacher, 'Teacher');

    $this->actingAs($teacher)->get(route('accounting.invoices.index'))->assertForbidden();
    $this->actingAs($this->accountant)->get(route('accounting.invoices.index'))->assertOk();
});
