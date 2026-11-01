<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Services\Fees\FeeCollection;
use App\Services\Fees\Invoices;
use App\Services\Fees\LateFees;
use App\Services\Payroll\Payroll;
use App\Services\Students\StudentRecords;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-11-20 05:00:00');

    $this->school = makeSchool(['slug' => 'alpha', 'settings' => [
        'timezone' => 'Asia/Karachi',
        'fees' => ['late_fee' => ['enabled' => true, 'type' => 'fixed', 'amount' => 300, 'grace_days' => 0, 'max_amount' => null]],
    ]]);
    seedSchoolRoles($this->school);
    tenancy()->set($this->school);

    $this->accountant = makeUserWithRole($this->school, UserType::Accountant, 'Accountant');
    $this->actingAs($this->accountant);

    AcademicYear::factory()->current()->create(['name' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30']);
    $campus = App\Models\Campus::query()->create(['name' => 'Main', 'is_main' => true]);
    $class = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 1', 'numeric_level' => 1]);
    $this->student = app(StudentRecords::class)->admit(['name' => 'Ali', 'class_id' => $class->id, 'admission_date' => '2026-08-01'])->student;

    $this->invoice = app(Invoices::class)->create(
        $this->student,
        [['fee_structure_id' => null, 'description' => 'Tuition', 'amount' => 10000, 'discount_amount' => 0, 'net_amount' => 10000, 'discount_breakdown' => null]],
        Carbon::parse('2026-10-01'),
        Carbon::parse('2026-10-10'),
        null,
        $this->accountant,
    );

    app(LateFees::class)->applyOverdue();
    $this->invoice->refresh();
});

afterEach(fn () => Carbon::setTestNow());

it('charges no late fee on a payment received before the due date, however late it is recorded', function () {
    expect((float) $this->invoice->late_fee_amount)->toBe(300.0)
        ->and($this->invoice->status)->toBe(InvoiceStatus::Overdue);

    app(FeeCollection::class)->collect($this->invoice, 10000, PaymentMethod::Cash, Carbon::parse('2026-10-05'), $this->accountant);

    $invoice = $this->invoice->fresh();

    expect((float) $invoice->late_fee_amount)->toBe(0.0)
        ->and((float) $invoice->total_amount)->toBe(10000.0)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid);
});

it('still charges the late fee on a payment received after the due date', function () {
    expect(fn () => app(FeeCollection::class)->collect($this->invoice, 10300.01, PaymentMethod::Cash, Carbon::parse('2026-10-15'), $this->accountant))
        ->toThrow(ActionFailed::class, 'is owed');

    app(FeeCollection::class)->collect($this->invoice, 10300, PaymentMethod::Cash, Carbon::parse('2026-10-15'), $this->accountant);

    expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and((float) $this->invoice->fresh()->total_amount)->toBe(10300.0);
});

it('refuses a payment dated before the invoice was issued', function () {
    expect(fn () => app(FeeCollection::class)->collect($this->invoice, 100, PaymentMethod::Cash, Carbon::parse('2001-01-01'), $this->accountant))
        ->toThrow(ActionFailed::class, 'before the invoice was issued');
});

it('removes the late fee, and says so in the log, when the due date is extended', function () {
    app(Invoices::class)->updateDetails($this->invoice, Carbon::parse('2026-12-10'), null, $this->accountant);

    $invoice = $this->invoice->fresh();

    expect((float) $invoice->late_fee_amount)->toBe(0.0)
        ->and((float) $invoice->total_amount)->toBe(10000.0)
        ->and($invoice->status)->toBe(InvoiceStatus::Unpaid);

    $logged = Spatie\Activitylog\Models\Activity::query()->where('description', 'Invoice updated')->latest('id')->first();

    expect($logged->properties['changed'])->toContain('late_fee_amount');
});

it('does not run payroll for a month that has not started, or pay before the month', function () {
    $hr = makeUserWithRole($this->school, UserType::Hr, 'HR');
    $payroll = app(Payroll::class);

    expect(fn () => $payroll->generate('2026-12', $hr))->toThrow(ActionFailed::class, 'until the month has started');
    expect($payroll->generate('2026-11', $hr))->toBe(0);

    $staff = App\Models\StaffProfile::query()->create(['user_id' => $hr->id, 'employee_id' => 'H1', 'salary' => 1000]);
    $payroll->generate('2026-11', $hr);
    $record = App\Models\PayrollRecord::query()->where('staff_profile_id', $staff->id)->firstOrFail();
    $payroll->approve($record, $hr);

    expect(fn () => $payroll->markPaid($record->refresh(), Carbon::parse('2001-01-01'), PaymentMethod::Cash, $hr))
        ->toThrow(ActionFailed::class, 'before that month');
});

it('refuses to delete a student with fee records', function () {
    expect(fn () => app(StudentRecords::class)->delete($this->student, $this->accountant))
        ->toThrow(ActionFailed::class, 'fee or exam records');

    expect(App\Models\StudentProfile::query()->find($this->student->id))->not->toBeNull();
});
