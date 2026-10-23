{{--
    Report cards: one page per student. Rendered as a printable web page
    ($printable = true) and as a PDF (dompdf), so layout uses tables and
    inline CSS only.

    $cards: list of ResultSheet::forStudent() arrays
    $school: the School
    $scale: the GradeScale used (for the grade key), or null
--}}
@php
    $format = $school->setting('date_format');
    $logo = null;
    if ($school->logo && Storage::disk('public')->exists($school->logo)) {
        $logo = 'data:'.Storage::disk('public')->mimeType($school->logo).';base64,'.base64_encode(Storage::disk('public')->get($school->logo));
    }
    $trim = fn ($value) => App\Services\Exams\Gradebook::trim($value);
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Report card') }} · {{ $school->name }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size: 11px; color: #1f2937; background: #fff; }
        .sheet { max-width: 780px; margin: 0 auto; padding: 4px; }
        .page-break { page-break-after: always; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: middle; }
        .logo { width: 56px; height: 56px; }
        .logo img { width: 56px; height: 56px; object-fit: contain; }
        .initial { display: inline-block; width: 56px; height: 56px; line-height: 56px; text-align: center; border-radius: 8px; background: #4f46e5; color: #fff; font-size: 24px; font-weight: bold; }
        .school-name { font-size: 18px; font-weight: bold; margin: 0; }
        .muted { color: #6b7280; }
        .title { text-align: right; }
        .title h2 { margin: 0; font-size: 16px; letter-spacing: 1px; text-transform: uppercase; color: #4f46e5; }
        .rule { border-top: 2px solid #4f46e5; margin: 10px 0 12px; }
        .info td { padding: 3px 6px 3px 0; }
        .info .label { color: #6b7280; width: 18%; }
        .info .value { font-weight: bold; width: 32%; }
        .marks { margin-top: 12px; }
        .marks th { background: #eef2ff; color: #3730a3; text-align: left; font-weight: bold; padding: 6px; border: 1px solid #c7d2fe; }
        .marks td { padding: 6px; border: 1px solid #e5e7eb; }
        .marks .num { text-align: center; }
        .marks tfoot td { font-weight: bold; background: #f9fafb; }
        .fail { color: #dc2626; font-weight: bold; }
        .pass { color: #16a34a; font-weight: bold; }
        .summary { margin-top: 12px; }
        .summary td { width: 25%; border: 1px solid #e5e7eb; padding: 8px; text-align: center; }
        .summary .big { display: block; font-size: 16px; font-weight: bold; margin-top: 2px; }
        .box { margin-top: 12px; border: 1px solid #e5e7eb; padding: 8px 10px; min-height: 48px; }
        .box h4 { margin: 0 0 4px; font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; }
        .key { margin-top: 10px; font-size: 9.5px; color: #6b7280; }
        .signatures { margin-top: 40px; }
        .signatures td { width: 33%; text-align: center; padding-top: 4px; }
        .signatures span { display: inline-block; width: 80%; border-top: 1px solid #9ca3af; padding-top: 4px; }
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

@foreach ($cards as $card)
    @php
        $result = $card['result'];
        $student = $card['student'];
        $exam = $card['exam'];
    @endphp
    <div class="sheet {{ $loop->last ? '' : 'page-break' }}">
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
                    <h2>{{ __('Report card') }}</h2>
                    <div><strong>{{ $exam->name }}</strong></div>
                    <div class="muted">{{ collect([$exam->academicYear?->name, $exam->term?->name])->filter()->implode(' · ') }}</div>
                </td>
            </tr>
        </table>
        <div class="rule"></div>

        <table class="info">
            <tr>
                <td class="label">{{ __('Student') }}</td><td class="value">{{ $student->user?->name }}</td>
                <td class="label">{{ __('Admission no.') }}</td><td class="value">{{ $student->admission_number }}</td>
            </tr>
            <tr>
                <td class="label">{{ __('Class') }}</td><td class="value">{{ $card['class']->name }}{{ $card['section'] ? ' · '.$card['section']->name : '' }}</td>
                <td class="label">{{ __('Roll no.') }}</td><td class="value">{{ $card['enrollment']->roll_number ?? '—' }}</td>
            </tr>
            <tr>
                <td class="label">{{ __('Date of birth') }}</td><td class="value">{{ $student->dob?->format($format) ?? '—' }}</td>
                <td class="label">{{ __('Class teacher') }}</td><td class="value">{{ $card['class_teacher'] ?? '—' }}</td>
            </tr>
        </table>

        <table class="marks">
            <thead>
                <tr>
                    <th>{{ __('Subject') }}</th>
                    <th class="num" style="width: 11%">{{ __('Total') }}</th>
                    <th class="num" style="width: 11%">{{ __('Pass') }}</th>
                    <th class="num" style="width: 13%">{{ __('Obtained') }}</th>
                    <th class="num" style="width: 10%">{{ __('Grade') }}</th>
                    <th style="width: 26%">{{ __('Remarks') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($card['subjects'] as $subject)
                    <tr>
                        <td>{{ $subject['subject'] }}</td>
                        <td class="num">{{ $trim($subject['max']) }}</td>
                        <td class="num">{{ $trim($subject['passing']) }}</td>
                        <td class="num {{ $subject['passed'] === false ? 'fail' : '' }}">
                            @if ($subject['absent'])
                                {{ __('Absent') }}
                            @elseif ($subject['obtained'] !== null)
                                {{ $trim($subject['obtained']) }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="num">{{ $subject['grade'] ?? '—' }}</td>
                        <td>{{ $subject['remarks'] }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>{{ __('Total') }}</td>
                    <td class="num">{{ $trim($result['max']) }}</td>
                    <td></td>
                    <td class="num">{{ $trim($result['obtained']) }}</td>
                    <td class="num">{{ $result['grade'] ?? '—' }}</td>
                    <td>{{ $result['grade_remark'] }}</td>
                </tr>
            </tfoot>
        </table>

        <table class="summary">
            <tr>
                <td>{{ __('Percentage') }}<span class="big">{{ $result['percentage'] !== null ? $result['percentage'].'%' : '—' }}</span></td>
                <td>{{ __('Result') }}
                    <span class="big {{ $result['passed'] === true ? 'pass' : ($result['passed'] === false ? 'fail' : '') }}">
                        {{ $result['passed'] === null ? __('Incomplete') : ($result['passed'] ? __('Pass') : __('Fail')) }}
                    </span>
                </td>
                <td>{{ $card['section'] ? __('Position in section') : __('Position in class') }}
                    <span class="big">{{ $result['rank'] ? $result['rank'].' / '.$card['section_size'] : '—' }}</span>
                </td>
                <td>{{ __('Attendance') }}
                    <span class="big">{{ $card['attendance']['rate'] !== null ? $card['attendance']['rate'].'%' : '—' }}</span>
                    <span class="muted">
                        {{ $card['attendance']['marked'] ? __(':present of :days days', ['present' => $card['attendance']['present'], 'days' => $card['attendance']['marked'] - $card['attendance']['excused']]) : '' }}
                        {{ $card['attendance']['excused'] ? __('(:count on leave)', ['count' => $card['attendance']['excused']]) : '' }}
                    </span>
                </td>
            </tr>
        </table>

        @if ($card['section'] && $card['class_rank'])
            <div class="muted" style="margin-top: 6px">{{ __('Position in class: :rank', ['rank' => $card['class_rank']]) }}</div>
        @endif

        <div class="box">
            <h4>{{ __('Class teacher\'s remarks') }}</h4>
            {{ $card['remarks'] ?: '' }}
        </div>

        @if (! empty($scale) && $scale->items->isNotEmpty())
            <div class="key">
                <strong>{{ __('Grades') }}:</strong>
                @foreach ($scale->items as $item)
                    {{ $item->grade }} ({{ $trim($item->min_percentage) }}–{{ $trim($item->max_percentage) }}%){{ $loop->last ? '' : ' · ' }}
                @endforeach
            </div>
        @endif

        <table class="signatures">
            <tr>
                <td><span>{{ __('Class teacher') }}</span></td>
                <td><span>{{ __('Principal') }}</span></td>
                <td><span>{{ __('Parent / guardian') }}</span></td>
            </tr>
        </table>

        <div class="footer">{{ __('Issued :date · :school', ['date' => $school->today()->format($format), 'school' => $school->name]) }}</div>
    </div>
@endforeach
</body>
</html>
