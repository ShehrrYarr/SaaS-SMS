{{--
    A salary slip. $record (PayrollRecord with staff.user and staff.department).
--}}
@php
    $format = $school->setting('date_format');
    $staff = $record->staff;
    $money = fn ($amount) => App\Support\Billing\Money::school($amount);
    $days = fn ($value) => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
    $attendance = $record->breakdown['attendance'] ?? [];
    $allowances = $record->breakdown['allowances'] ?? [];
    $deductions = $record->breakdown['deductions'] ?? [];
    $rows = max(count($allowances) + 1, count($deductions), 1);
@endphp

@extends('documents.layout', ['title' => __('Salary slip'), 'watermark' => $record->isPaid() ? null : __('Not paid')])

@section('heading')
    <div><strong>{{ $record->monthLabel() }}</strong></div>
    <div style="margin-top: 4px">
        <span class="stamp {{ $record->isPaid() ? 'paid' : 'open' }}">{{ $record->status->label() }}</span>
    </div>
@endsection

@section('content')
    <table class="info">
        <tr>
            <td class="label">{{ __('Name') }}</td><td class="value">{{ $staff?->user?->name }}</td>
            <td class="label">{{ __('Employee ID') }}</td><td class="value">{{ $staff?->employee_id }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('Designation') }}</td><td class="value">{{ $staff?->designation ?? '—' }}</td>
            <td class="label">{{ __('Department') }}</td><td class="value">{{ $staff?->department?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('Working days') }}</td><td class="value">{{ $attendance['working_days'] ?? $record->working_days }}</td>
            <td class="label">{{ __('Paid on') }}</td><td class="value">{{ $record->paid_on ? $record->paid_on->format($format).' · '.$record->payment_method?->label() : '—' }}</td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th style="width: 34%">{{ __('Earnings') }}</th><th class="num" style="width: 16%">{{ __('Amount') }}</th>
                <th style="width: 34%">{{ __('Deductions') }}</th><th class="num" style="width: 16%">{{ __('Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @for ($i = 0; $i < $rows; $i++)
                @php
                    $earning = $i === 0 ? ['label' => __('Basic salary'), 'amount' => $record->basic_salary] : ($allowances[$i - 1] ?? null);
                    $deduction = $deductions[$i] ?? null;
                @endphp
                <tr>
                    <td>{{ $earning['label'] ?? '' }}</td><td class="num">{{ $earning ? $money($earning['amount']) : '' }}</td>
                    <td>{{ $deduction['label'] ?? '' }}</td><td class="num">{{ $deduction ? $money($deduction['amount']) : '' }}</td>
                </tr>
            @endfor
            <tr style="font-weight: bold; background: #f9fafb">
                <td>{{ __('Gross earnings') }}</td><td class="num">{{ $money((float) $record->basic_salary + (float) $record->bonuses) }}</td>
                <td>{{ __('Total deductions') }}</td><td class="num">{{ $money($record->deductions) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="amount-box">
        <table>
            <tr>
                <td>
                    <div class="muted">{{ __('Net pay') }}</div>
                    <div class="big">{{ $money($record->net_salary) }}</div>
                </td>
                <td style="text-align: right; vertical-align: bottom" class="muted">
                    {{ __('Worked :worked · paid leave :paid · unpaid leave :unpaid · absent :absent', [
                        'worked' => $days($attendance['present'] ?? $record->present_days),
                        'paid' => $days($attendance['paid_leave'] ?? 0),
                        'unpaid' => $days($attendance['unpaid_leave'] ?? 0),
                        'absent' => $days($attendance['absent'] ?? 0),
                    ]) }}
                </td>
            </tr>
        </table>
    </div>

    <p class="muted" style="margin-top: 12px">{{ __('This is a computer-generated slip.') }}</p>

    <table class="signatures">
        <tr>
            <td><span>{{ __('HR / Accounts') }}</span></td>
            <td><span>{{ __('Employee') }}</span></td>
        </tr>
    </table>
@endsection
