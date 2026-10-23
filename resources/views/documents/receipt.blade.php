{{--
    A fee receipt. $payment with invoice.items, invoice.student (user,
    schoolClass, section, parents.user) and receivedBy loaded.
--}}
@php
    $format = $school->setting('date_format');
    $invoice = $payment->invoice;
    $student = $invoice?->student;
    $guardian = $student?->parents->sortByDesc(fn ($parent) => (bool) $parent->pivot->is_primary)->first();
    $money = fn ($amount) => App\Support\Billing\Money::school($amount);
    $inWords = null;
    if (extension_loaded('intl')) {
        $whole = (int) floor((float) $payment->amount_paid);
        $fraction = (int) round(((float) $payment->amount_paid - $whole) * 100);
        $inWords = Str::ucfirst(Illuminate\Support\Number::spell($whole)).($fraction ? ' '.__('and :cents/100', ['cents' => $fraction]) : '').' '.__('only');
    }
@endphp

@extends('documents.layout', ['title' => __('Payment receipt'), 'watermark' => $payment->isVoided() ? __('Void') : null])

@section('heading')
    <div><strong>{{ $payment->receipt_number }}</strong></div>
    <div class="muted">{{ $payment->payment_date->format($format) }}</div>
    @if ($payment->isVoided())
        <div style="margin-top: 4px"><span class="stamp void">{{ __('Void') }}</span></div>
    @endif
@endsection

@section('content')
    <table class="info">
        <tr>
            <td class="label">{{ __('Received from') }}</td><td class="value">{{ $guardian?->user?->name ?? $student?->user?->name }}</td>
            <td class="label">{{ __('Invoice') }}</td><td class="value">{{ $invoice?->invoice_number }}{{ $invoice?->billing_period ? ' · '.$invoice->periodLabel() : '' }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('Student') }}</td><td class="value">{{ $student?->user?->name }} ({{ $student?->admission_number }})</td>
            <td class="label">{{ __('Method') }}</td><td class="value">{{ $payment->payment_method->label() }}{{ $payment->transaction_id ? ' · '.$payment->transaction_id : '' }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('Class') }}</td><td class="value">{{ $student?->placementLabel() ?? '—' }}</td>
            <td class="label">{{ __('Received by') }}</td><td class="value">{{ $payment->receivedBy?->name ?? '—' }}</td>
        </tr>
    </table>

    <div class="amount-box">
        <table>
            <tr>
                <td>
                    <div class="muted">{{ __('Amount received') }}</div>
                    <div class="big">{{ $money($payment->amount_paid) }}</div>
                    @if ($inWords)<div class="muted">{{ $inWords }}</div>@endif
                </td>
                <td style="text-align: right; vertical-align: bottom">
                    <div class="muted">{{ __('Balance on the invoice now') }}</div>
                    <strong>{{ $money($invoice && ! $invoice->isCancelled() ? max($invoice->balanceAmount(), 0) : 0) }}</strong>
                </td>
            </tr>
        </table>
    </div>

    @if ($invoice)
        <table class="lines">
            <thead><tr><th>{{ __('For') }}</th><th class="num" style="width: 20%">{{ __('Amount') }}</th></tr></thead>
            <tbody>
                @foreach ($invoice->items as $item)
                    <tr><td>{{ $item->description }}</td><td class="num">{{ $money($item->net_amount) }}</td></tr>
                @endforeach
                @if ((float) $invoice->late_fee_amount > 0)
                    <tr><td>{{ __('Late fee') }}</td><td class="num">{{ $money($invoice->late_fee_amount) }}</td></tr>
                @endif
            </tbody>
        </table>
    @endif

    @if ($payment->notes)
        <div class="box"><h4>{{ __('Notes') }}</h4>{{ $payment->notes }}</div>
    @endif

    @if ($payment->isVoided())
        <div class="box" style="border-color: #fecaca">
            <h4 style="color: #dc2626">{{ __('Voided') }}</h4>
            {{ $school->localTime($payment->voided_at)->format($format) }} · {{ $payment->void_reason }}
        </div>
    @endif

    <table class="signatures">
        <tr>
            <td><span>{{ __('Cashier') }}</span></td>
            <td><span>{{ __('School stamp') }}</span></td>
        </tr>
    </table>
@endsection
