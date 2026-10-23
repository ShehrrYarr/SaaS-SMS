{{--
    A fee invoice. $invoice with items, payments (and student.user,
    student.schoolClass, student.section, student.parents.user loaded).
--}}
@php
    $format = $school->setting('date_format');
    $student = $invoice->student;
    $guardian = $student?->parents->sortByDesc(fn ($parent) => (bool) $parent->pivot->is_primary)->first();
    $money = fn ($amount) => App\Support\Billing\Money::school($amount);
    $payments = $invoice->payments->reject(fn ($payment) => $payment->isVoided());
    $lateFeeRule = $school->setting('fees.late_fee.enabled');
@endphp

@extends('documents.layout', ['title' => __('Fee invoice'), 'watermark' => $invoice->isCancelled() ? __('Cancelled') : null])

@section('heading')
    <div><strong>{{ $invoice->invoice_number }}</strong></div>
    @if ($invoice->billing_period)<div class="muted">{{ $invoice->periodLabel() }}</div>@endif
    <div style="margin-top: 4px">
        @if ($invoice->isCancelled())
            <span class="stamp void">{{ __('Cancelled') }}</span>
        @elseif ($invoice->balanceAmount() <= 0)
            <span class="stamp paid">{{ __('Paid') }}</span>
        @else
            <span class="stamp open">{{ $invoice->status->label() }}</span>
        @endif
    </div>
@endsection

@section('content')
    <table class="info">
        <tr>
            <td class="label">{{ __('Student') }}</td><td class="value">{{ $student?->user?->name }}</td>
            <td class="label">{{ __('Issue date') }}</td><td class="value">{{ $invoice->issue_date->format($format) }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('Admission no.') }}</td><td class="value">{{ $student?->admission_number }}</td>
            <td class="label">{{ __('Due date') }}</td><td class="value">{{ $invoice->due_date->format($format) }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('Class') }}</td><td class="value">{{ $student?->placementLabel() ?? '—' }}</td>
            <td class="label">{{ __('Parent / guardian') }}</td><td class="value">{{ $guardian?->user?->name ?? '—' }}{{ $guardian?->user?->phone ? ' · '.$guardian->user->phone : '' }}</td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('Description') }}</th>
                <th class="num" style="width: 16%">{{ __('Amount') }}</th>
                <th class="num" style="width: 16%">{{ __('Reduction') }}</th>
                <th class="num" style="width: 16%">{{ __('Net') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>
                        {{ $item->description }}
                        @foreach ($item->discount_breakdown ?? [] as $reduction)
                            <span class="sub">{{ $reduction['label'] }}: -{{ $money($reduction['amount']) }}</span>
                        @endforeach
                    </td>
                    <td class="num">{{ $money($item->amount) }}</td>
                    <td class="num">{{ (float) $item->discount_amount > 0 ? '-'.$money($item->discount_amount) : '—' }}</td>
                    <td class="num">{{ $money($item->net_amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td class="muted">{{ __('Subtotal') }}</td><td class="num">{{ $money($invoice->subtotal) }}</td></tr>
        @if ((float) $invoice->discount_amount > 0)
            <tr><td class="muted">{{ __('Reductions') }}</td><td class="num">-{{ $money($invoice->discount_amount) }}</td></tr>
        @endif
        @if ((float) $invoice->late_fee_amount > 0)
            <tr><td class="muted">{{ __('Late fee') }}</td><td class="num due">{{ $money($invoice->late_fee_amount) }}</td></tr>
        @endif
        <tr><td><strong>{{ __('Total') }}</strong></td><td class="num"><strong>{{ $money($invoice->total_amount) }}</strong></td></tr>
        @if ((float) $invoice->paid_amount > 0)
            <tr><td class="muted">{{ __('Paid') }}</td><td class="num">-{{ $money($invoice->paid_amount) }}</td></tr>
        @endif
        <tr class="grand"><td>{{ __('Balance due') }}</td><td class="num">{{ $money($invoice->isCancelled() ? 0 : max($invoice->balanceAmount(), 0)) }}</td></tr>
    </table>

    @if ($payments->isNotEmpty())
        <div class="box">
            <h4>{{ __('Payments received') }}</h4>
            @foreach ($payments as $payment)
                <div>{{ $payment->receipt_number }} · {{ $payment->payment_date->format($format) }} · {{ $payment->payment_method->label() }} · <strong>{{ $money($payment->amount_paid) }}</strong></div>
            @endforeach
        </div>
    @endif

    @if ($invoice->notes)
        <div class="box">
            <h4>{{ __('Notes') }}</h4>
            <div style="white-space: pre-line">{{ $invoice->notes }}</div>
        </div>
    @endif

    @if (! $invoice->isCancelled() && $invoice->balanceAmount() > 0)
        <p class="muted" style="margin-top: 12px">
            {{ __('Please pay by :date.', ['date' => $invoice->due_date->format($format)]) }}
            @if ($lateFeeRule) {{ __('A late fee is charged on payments after the due date.') }} @endif
        </p>
    @endif

    <table class="signatures">
        <tr>
            <td><span>{{ __('Accounts office') }}</span></td>
            <td><span>{{ __('Received by (parent / guardian)') }}</span></td>
        </tr>
    </table>
@endsection
