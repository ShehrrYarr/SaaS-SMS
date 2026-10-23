<?php

/*
 * Mark approved salaries paid: one record, or every approved record of a
 * month. Each is posted to expenses and the staff member is notified.
 *     $dispatch('open-modal', { component: 'forms.payroll-payment', params: { record: 5 } })
 *     $dispatch('open-modal', { component: 'forms.payroll-payment', params: { month: '2026-10' } })
 */

use App\Enums\PaymentMethod;
use App\Enums\PayrollStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\PayrollRecord;
use App\Services\Payroll\Payroll;
use App\Support\Billing\Money;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, state, uses};

uses([InteractsWithUi::class]);

state(['record' => null, 'month' => null])->locked();

state(['paid_on' => '', 'payment_method' => PaymentMethod::BankTransfer->value]);

mount(function (?int $record = null, ?string $month = null) {
    $this->authorize('payroll.pay');
    abort_unless($record || Payroll::isMonth($month), 404);

    $this->record = $record ? PayrollRecord::query()->findOrFail($record)->id : null;
    $this->month = $record ? null : $month;
    $this->paid_on = tenant()->today()->toDateString();
});

$records = computed(fn () => PayrollRecord::query()
    ->with('staff.user')
    ->where('status', PayrollStatus::Approved)
    ->when($this->record, fn ($query) => $query->whereKey($this->record), fn ($query) => $query->where('month_year', $this->month))
    ->get());

$save = function (Payroll $payroll) {
    $this->authorize('payroll.pay');
    $data = $this->validate([
        'paid_on' => ['required', 'date', 'before_or_equal:'.tenant()->today()->toDateString()],
        'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
    ], attributes: ['paid_on' => __('date'), 'payment_method' => __('method')]);

    if ($this->records->isEmpty()) {
        $this->addError('paid_on', __('There is nothing approved to pay.'));

        return;
    }

    try {
        foreach ($this->records as $record) {
            $payroll->markPaid($record, Carbon::parse($data['paid_on']), PaymentMethod::from($data['payment_method']), auth()->user());
        }
    } catch (ActionFailed $e) {
        $this->addError('paid_on', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->dispatch('payroll-changed');
    $this->toast(trans_choice(':count salary marked paid.|:count salaries marked paid.', $this->records->count(), ['count' => $this->records->count()]));
};

?>

@php($records = $this->records)

<x-modal.form :title="$record ? __('Mark salary paid') : __('Pay approved salaries')" :submit-label="__('Mark paid')" submit-color="success"
              :subtitle="$record ? $records->first()?->staff?->user?->name : __(':month · :count staff', ['month' => Payroll::monthLabel($month), 'count' => $records->count()])">
    <div class="d-flex justify-content-between mb-4">
        <span class="text-muted">{{ __('Total net pay') }}</span>
        <span class="fs-5 fw-semibold">{{ Money::school($records->sum(fn ($row) => (float) $row->net_salary)) }}</span>
    </div>
    <div class="row">
        <div class="col-sm-6"><x-form.input name="paid_on" type="date" :label="__('Paid on')" required wrapper="mb-0" /></div>
        <div class="col-sm-6"><x-form.select name="payment_method" :label="__('Method')" :options="PaymentMethod::options()" :placeholder="false" required wrapper="mb-0" /></div>
    </div>
    <p class="text-muted fs-13 mt-3 mb-0">{{ __('Each salary is added to expenses under Salaries, and the staff member can then see their salary slip.') }}</p>
</x-modal.form>
