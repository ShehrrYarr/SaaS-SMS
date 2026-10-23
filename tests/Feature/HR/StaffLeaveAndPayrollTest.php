<?php

use App\Enums\LeaveStatus;
use App\Enums\PaymentMethod;
use App\Enums\PayrollStatus;
use App\Enums\StaffAttendanceStatus;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\PayrollRecord;
use App\Models\StaffAttendance;
use App\Services\Leave\LeaveBalances;
use App\Services\Leave\StaffLeave;
use App\Services\Payroll\Payroll;
use App\Services\Schools\SeedSchoolDefaults;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    app(SeedSchoolDefaults::class)->handle($this->school);
    tenancy()->set($this->school);

    $this->year = AcademicYear::factory()->current()->create(['start_date' => '2026-08-01', 'end_date' => '2027-06-30']);

    $this->hrUser = makeUserWithRole($this->school, UserType::Hr, 'HR');
    $this->hr = makeStaffProfile($this->school, $this->hrUser);
    $this->teacherUser = makeUserWithRole($this->school, UserType::Teacher, 'Teacher');
    $this->teacher = makeStaffProfile($this->school, $this->teacherUser);
    $this->teacher->update(['salary' => 25000, 'joining_date' => '2025-01-01']);

    $this->casual = LeaveType::query()->where('name', 'Casual Leave')->sole();   // 10 days, paid
    $this->unpaid = LeaveType::query()->where('name', 'Unpaid Leave')->sole();   // no limit, unpaid

    Carbon::setTestNow('2026-11-02 09:00:00'); // a Monday; Sundays are off
});

afterEach(fn () => Carbon::setTestNow());

function staffLeave(): StaffLeave
{
    return app(StaffLeave::class);
}

it('lets staff apply within their allowance, and HR approve', function () {
    StaffAttendance::query()->create(['staff_profile_id' => $this->teacher->id, 'date' => '2026-11-03', 'status' => StaffAttendanceStatus::Absent]);

    // Tue 3 – Thu 5 November: 3 working days.
    $request = staffLeave()->apply($this->teacher, $this->casual, Carbon::parse('2026-11-03'), Carbon::parse('2026-11-05'), 'Family wedding', $this->teacherUser);

    expect((float) $request->days)->toBe(3.0)
        ->and($request->status)->toBe(LeaveStatus::Pending)
        ->and(app(LeaveBalances::class)->available($this->teacher, $this->casual, $this->year))->toBe(7.0)
        ->and($this->hrUser->notifications()->count())->toBe(1);

    // Pending leave counts against the allowance.
    expect(fn () => staffLeave()->apply($this->teacher, $this->casual, Carbon::parse('2026-11-09'), Carbon::parse('2026-11-17'), 'Trip', $this->teacherUser))
        ->toThrow(ActionFailed::class, 'Only 7 day(s) of Casual Leave are left this year.');

    // Overlapping dates are refused.
    expect(fn () => staffLeave()->apply($this->teacher, $this->unpaid, Carbon::parse('2026-11-05'), Carbon::parse('2026-11-06'), 'x', $this->teacherUser))
        ->toThrow(ActionFailed::class);

    staffLeave()->approve($request, $this->hrUser, 'Enjoy');

    $balance = LeaveBalance::query()->where('staff_profile_id', $this->teacher->id)->where('leave_type_id', $this->casual->id)->sole();
    expect($request->fresh()->status)->toBe(LeaveStatus::Approved)
        ->and((float) $balance->used)->toBe(3.0)
        ->and((float) $balance->allocated)->toBe(10.0)
        ->and(StaffAttendance::query()->where('staff_profile_id', $this->teacher->id)->value('status'))->toBe(StaffAttendanceStatus::OnLeave)
        ->and($this->teacherUser->notifications()->count())->toBe(1);
});

it('counts working days, half days, and stops people deciding their own leave', function () {
    // Sat 7 – Mon 9 November skips Sunday the 8th.
    $span = staffLeave()->apply($this->teacher, $this->unpaid, Carbon::parse('2026-11-07'), Carbon::parse('2026-11-09'), 'Moving house', $this->teacherUser);
    $half = staffLeave()->apply($this->teacher, $this->casual, Carbon::parse('2026-11-12'), Carbon::parse('2026-11-12'), 'Doctor', $this->teacherUser, halfDay: true);

    expect((float) $span->days)->toBe(2.0)
        ->and((float) $half->days)->toBe(0.5);

    $own = staffLeave()->apply($this->hr, $this->casual, Carbon::parse('2026-11-20'), Carbon::parse('2026-11-20'), 'Errand', $this->hrUser);
    expect(staffLeave()->canReview($this->hrUser, $own))->toBeFalse()
        ->and(fn () => staffLeave()->approve($own, $this->hrUser))->toThrow(ActionFailed::class, 'You can\'t decide your own leave request.');

    // Only the applicant (or HR) can withdraw it.
    expect(fn () => staffLeave()->cancel($half, makeUserWithRole($this->school, UserType::Teacher, 'Teacher')))->toThrow(ActionFailed::class);
    staffLeave()->cancel($half, $this->teacherUser);
    expect($half->fresh()->status)->toBe(LeaveStatus::Cancelled);
});

it('applies for and reviews leave through the forms', function () {
    $this->actingAs($this->teacherUser);

    Volt::test('forms.staff-leave', ['as' => 'self'])
        ->set('leave_type_id', (string) $this->casual->id)
        ->set('start_date', '2026-11-04')
        ->set('end_date', '2026-11-04')
        ->set('reason', 'Appointment')
        ->call('save')
        ->assertHasNoErrors();

    $request = $this->teacher->leaveRequests()->sole();

    $this->actingAs($this->hrUser);
    Volt::test('forms.staff-leave-review', ['request' => $request->id, 'decision' => 'approve'])
        ->set('remarks', 'OK')
        ->call('save')
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe(LeaveStatus::Approved);

    // HR records leave for someone else, approved at once.
    Volt::test('forms.staff-leave', ['as' => 'hr'])
        ->set('staff_id', (string) $this->teacher->id)
        ->set('leave_type_id', (string) $this->unpaid->id)
        ->set('start_date', '2026-11-10')
        ->set('end_date', '2026-11-10')
        ->set('reason', 'Recorded by HR')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->teacher->leaveRequests()->where('status', LeaveStatus::Approved)->count())->toBe(2);
});

it('works out monthly pay from attendance and leave', function () {
    // November 2026 has 25 working days, so the daily rate is 1,000.
    StaffAttendance::query()->create(['staff_profile_id' => $this->teacher->id, 'date' => '2026-11-03', 'status' => StaffAttendanceStatus::Absent]);
    StaffAttendance::query()->create(['staff_profile_id' => $this->teacher->id, 'date' => '2026-11-06', 'status' => StaffAttendanceStatus::HalfDay]);
    $unpaid = staffLeave()->apply($this->teacher, $this->unpaid, Carbon::parse('2026-11-05'), Carbon::parse('2026-11-05'), 'Personal', $this->teacherUser);
    staffLeave()->approve($unpaid, $this->hrUser);

    // Joined on the 16th: the 12 working days before aren't paid.
    $newUser = makeUserWithRole($this->school, UserType::Staff, 'Librarian');
    $new = makeStaffProfile($this->school, $newUser);
    $new->update(['salary' => 25000, 'joining_date' => '2026-11-16']);

    expect(app(Payroll::class)->generate('2026-11', $this->hrUser))->toBe(2)
        ->and(app(Payroll::class)->generate('2026-11', $this->hrUser))->toBe(0); // HR has no salary

    $record = PayrollRecord::query()->where('staff_profile_id', $this->teacher->id)->sole();
    expect($record->working_days)->toBe(25)
        ->and((float) $record->deductions)->toBe(2500.0) // 1 unpaid + 1.5 absent
        ->and((float) $record->net_salary)->toBe(22500.0)
        ->and(collect($record->breakdown['deductions'])->pluck('label')->all())->toBe(['Unpaid leave (1 days)', 'Absent (1.5 days)']);

    expect((float) PayrollRecord::query()->where('staff_profile_id', $new->id)->value('net_salary'))->toBe(13000.0);

    // A hand-entered allowance survives recalculation; absences stop counting when the school says so.
    app(Payroll::class)->updateLines($record, [['label' => 'Transport', 'amount' => '500']], []);
    $school = tenant();
    $school->update(['settings' => array_replace_recursive($school->settings ?? [], ['payroll' => ['deduct_absences' => false]])]);
    tenancy()->set($school->fresh());

    app(Payroll::class)->recalculate($record->refresh());
    expect((float) $record->refresh()->bonuses)->toBe(500.0)
        ->and((float) $record->net_salary)->toBe(24500.0);
});

it('approves, pays and posts salaries, and can undo each step', function () {
    app(Payroll::class)->generate('2026-11', $this->hrUser);
    $record = PayrollRecord::query()->sole();
    $payroll = app(Payroll::class);

    expect(fn () => $payroll->markPaid($record, today(), PaymentMethod::BankTransfer, $this->hrUser))->toThrow(ActionFailed::class);

    $payroll->approve($record, $this->hrUser);
    expect(fn () => $payroll->updateLines($record->refresh(), [], []))->toThrow(ActionFailed::class);

    $payroll->markPaid($record->refresh(), today(), PaymentMethod::BankTransfer, $this->hrUser);
    $expense = Expense::query()->sole();
    expect($record->refresh()->status)->toBe(PayrollStatus::Paid)
        ->and((float) $expense->amount)->toBe(25000.0)
        ->and($expense->category->system_key)->toBe(FinanceCategory::SALARIES)
        ->and($expense->isAutomatic())->toBeTrue()
        ->and($this->teacherUser->notifications()->count())->toBe(1);

    $payroll->undoPayment($record, $this->hrUser);
    expect($record->refresh()->status)->toBe(PayrollStatus::Approved)
        ->and(Expense::query()->count())->toBe(0);

    $payroll->reopen($record, $this->hrUser);
    $payroll->discard($record->refresh());
    expect(PayrollRecord::withTrashed()->count())->toBe(0)
        ->and($payroll->generate('2026-11', $this->hrUser))->toBe(1);
});

it('shows salary slips to HR and to the staff member once paid', function () {
    app(Payroll::class)->generate('2026-11', $this->hrUser);
    $record = PayrollRecord::query()->sole();
    $payroll = app(Payroll::class);

    $this->actingAs($this->teacherUser)->get(route('tenant.payslips.show', $record->id))->assertNotFound();

    $payroll->approve($record, $this->hrUser);
    $this->actingAs($this->hrUser)->get(route('tenant.payslips.show', $record->id))->assertOk()->assertSee('November 2026');
    $this->actingAs($this->teacherUser)->get(route('tenant.payslips.show', $record->id))->assertNotFound();

    $payroll->markPaid($record->refresh(), today(), PaymentMethod::Cash, $this->hrUser);
    $this->actingAs($this->teacherUser)->get(route('tenant.payslips.show', $record->id))->assertOk();
    $this->actingAs($this->teacherUser)->get(route('tenant.payslips.pdf', $record->id))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->actingAs($this->teacherUser)->get(route('teacher.my-payslips'))->assertOk()->assertSee('November 2026');

    $colleague = makeUserWithRole($this->school, UserType::Teacher, 'Teacher');
    makeStaffProfile($this->school, $colleague);
    $this->actingAs($colleague)->get(route('tenant.payslips.show', $record->id))->assertNotFound();
});

it('shows the HR and self-service screens', function () {
    $this->actingAs($this->hrUser);
    foreach (['hr.leave.index', 'hr.leave.balances', 'hr.payroll.index', 'hr.payslips.index', 'hr.my-leave'] as $route) {
        $this->get(route($route))->assertOk();
    }

    // Opening the balances page sets up this year's balances.
    expect(LeaveBalance::query()->where('staff_profile_id', $this->teacher->id)->count())->toBe(LeaveType::query()->whereNotNull('days_per_year')->where('applies_to', '!=', 'student')->count());

    $this->actingAs($this->teacherUser)->get(route('teacher.my-leave'))->assertOk()->assertSee('Casual Leave');
    $this->actingAs($this->teacherUser)->get(route('hr.leave.index'))->assertForbidden();
});
