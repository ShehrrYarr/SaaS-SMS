{{--
    Shared shell for printable school documents (invoices, receipts, salary
    slips). Rendered as a web page ($printable = true, with a Print / PDF
    toolbar) and as a PDF by dompdf, so layout uses tables and inline CSS only.

        @extends('documents.layout', ['title' => __('Receipt')])
        @section('heading') ...right-hand side of the letterhead... @endsection
        @section('content') ... @endsection

    Expects $school; optional $printable, $pdfUrl, $watermark.
--}}
@php
    $logo = null;
    if ($school->logo && Storage::disk('public')->exists($school->logo)) {
        $logo = 'data:'.Storage::disk('public')->mimeType($school->logo).';base64,'.base64_encode(Storage::disk('public')->get($school->logo));
    }
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ $school->name }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size: 11px; color: #1f2937; background: #fff; }
        .sheet { max-width: 780px; margin: 0 auto; padding: 4px; position: relative; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: middle; }
        .logo img { width: 56px; height: 56px; object-fit: contain; }
        .initial { display: inline-block; width: 56px; height: 56px; line-height: 56px; text-align: center; border-radius: 8px; background: #4f46e5; color: #fff; font-size: 24px; font-weight: bold; }
        .school-name { font-size: 18px; font-weight: bold; margin: 0; }
        .muted { color: #6b7280; }
        .title { text-align: right; }
        .title h2 { margin: 0 0 2px; font-size: 16px; letter-spacing: 1px; text-transform: uppercase; color: #4f46e5; }
        .rule { border-top: 2px solid #4f46e5; margin: 10px 0 12px; }
        .info td { padding: 3px 6px 3px 0; vertical-align: top; }
        .info .label { color: #6b7280; width: 18%; }
        .info .value { font-weight: bold; width: 32%; }
        .lines { margin-top: 12px; }
        .lines th { background: #eef2ff; color: #3730a3; text-align: left; font-weight: bold; padding: 6px; border: 1px solid #c7d2fe; }
        .lines td { padding: 6px; border: 1px solid #e5e7eb; vertical-align: top; }
        .lines .num { text-align: right; white-space: nowrap; }
        .lines .sub { display: block; color: #6b7280; font-size: 9.5px; margin-top: 2px; }
        .totals { margin-top: 10px; width: 46%; margin-left: 54%; }
        .totals td { padding: 4px 6px; }
        .totals .num { text-align: right; }
        .totals .grand td { border-top: 2px solid #1f2937; font-weight: bold; font-size: 13px; }
        .due { color: #dc2626; }
        .box { margin-top: 12px; border: 1px solid #e5e7eb; padding: 8px 10px; }
        .box h4 { margin: 0 0 4px; font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; }
        .amount-box { margin-top: 12px; border: 2px solid #4f46e5; border-radius: 6px; padding: 10px 12px; }
        .amount-box .big { font-size: 20px; font-weight: bold; color: #3730a3; }
        .stamp { display: inline-block; padding: 3px 10px; border: 2px solid; border-radius: 4px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; font-size: 11px; }
        .stamp.paid { color: #16a34a; border-color: #16a34a; }
        .stamp.open { color: #d97706; border-color: #d97706; }
        .stamp.void { color: #dc2626; border-color: #dc2626; }
        .watermark { position: absolute; top: 38%; left: 0; right: 0; text-align: center; font-size: 90px; font-weight: bold; color: rgba(220, 38, 38, .12); transform: rotate(-20deg); }
        .signatures { margin-top: 36px; }
        .signatures td { width: 50%; text-align: center; padding-top: 4px; }
        .signatures span { display: inline-block; width: 70%; border-top: 1px solid #9ca3af; padding-top: 4px; }
        .footer { margin-top: 18px; font-size: 9px; color: #9ca3af; text-align: center; }
        .toolbar { max-width: 780px; margin: 16px auto; text-align: right; font-family: Arial, Helvetica, sans-serif; }
        .toolbar a, .toolbar button { display: inline-block; margin-left: 6px; padding: 8px 14px; border-radius: 6px; border: 1px solid #c7d2fe; background: #eef2ff; color: #3730a3; text-decoration: none; font-size: 13px; cursor: pointer; }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
@if ($printable ?? false)
    <div class="toolbar">
        <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        @isset($pdfUrl)<a href="{{ $pdfUrl }}">{{ __('Download PDF') }}</a>@endisset
    </div>
@endif

<div class="sheet">
    @if ($watermark ?? null)
        <div class="watermark">{{ $watermark }}</div>
    @endif

    <table class="head">
        <tr>
            <td style="width: 66px">
                @if ($logo)
                    <div class="logo"><img src="{{ $logo }}" alt=""></div>
                @else
                    <span class="initial">{{ Str::upper(Str::substr($school->name, 0, 1)) }}</span>
                @endif
            </td>
            <td>
                <p class="school-name">{{ $school->name }}</p>
                <div class="muted">{{ collect([$school->address, $school->city, $school->country])->filter()->implode(', ') }}</div>
                <div class="muted">{{ collect([$school->phone, $school->email])->filter()->implode(' · ') }}</div>
            </td>
            <td class="title">
                <h2>{{ $title }}</h2>
                @yield('heading')
            </td>
        </tr>
    </table>
    <div class="rule"></div>

    @yield('content')

    <div class="footer">{{ __('Generated on :date', ['date' => $school->localTime(now())->format($school->setting('date_format').', '.$school->setting('time_format'))]) }} · {{ $school->name }}</div>
</div>
</body>
</html>
