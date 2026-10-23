{{--
    The latest published result, and every report card to view or download.
    :card from StudentOverview::latestResult(); :exams from publishedExams().
--}}
@props(['card', 'exams', 'portal', 'student'])

<div {{ $attributes->class('card mb-0 h-100') }}>
    <div class="card-header hstack gap-2">
        <h5 class="card-title mb-0 flex-grow-1">{{ __('Results & report cards') }}</h5>
        <a href="{{ route($portal.'.results') }}" wire:navigate class="fs-13">{{ __('All results') }}</a>
    </div>
    @if ($exams->isEmpty())
        <x-empty-state icon="ri-award-line" :title="__('No results yet')" :message="__('Results appear here once the school publishes them.')" class="py-6" />
    @else
        @if ($card)
            @php($result = $card['result'])
            <div class="card-body border-bottom">
                <small class="text-muted d-block mb-2">{{ __('Latest: :exam', ['exam' => $card['exam']->name]) }}</small>
                <div class="row g-2 text-center">
                    <div class="col-3">
                        <span class="fs-5 fw-semibold d-block">{{ $result['percentage'] !== null ? round($result['percentage'], 1).'%' : '—' }}</span>
                        <small class="text-muted">{{ __('Score') }}</small>
                    </div>
                    <div class="col-3">
                        <span class="fs-5 fw-semibold d-block">{{ $result['grade'] ?? '—' }}</span>
                        <small class="text-muted">{{ __('Grade') }}</small>
                    </div>
                    <div class="col-3">
                        <span class="fs-5 fw-semibold d-block">{{ $result['rank'] ?? '—' }}</span>
                        <small class="text-muted">{{ __('Position') }}</small>
                    </div>
                    <div class="col-3">
                        @if ($result['passed'] === null)
                            <span class="fs-5 fw-semibold d-block text-muted">—</span>
                        @else
                            <span @class(['fs-5 fw-semibold d-block', 'text-success' => $result['passed'], 'text-danger' => ! $result['passed']])>{{ $result['passed'] ? __('Pass') : __('Fail') }}</span>
                        @endif
                        <small class="text-muted">{{ __('Result') }}</small>
                    </div>
                </div>
            </div>
        @endif
        <ul class="list-group list-group-flush">
            @foreach ($exams->take(4) as $exam)
                <li class="list-group-item hstack gap-2" wire:key="report-card-{{ $exam->id }}">
                    <i class="ri-file-text-line text-muted"></i>
                    <div class="min-w-0 flex-grow-1">
                        <span class="d-block text-truncate">{{ $exam->name }}</span>
                        <small class="text-muted">{{ $exam->academicYear?->name }}</small>
                    </div>
                    <a href="{{ route($portal.'.report-card', ['examination' => $exam->id, 'student' => $student->id]) }}" target="_blank" class="btn btn-sm btn-light" title="{{ __('View report card') }}"><i class="ri-printer-line"></i></a>
                    <a href="{{ route($portal.'.report-card.pdf', ['examination' => $exam->id, 'student' => $student->id]) }}" class="btn btn-sm btn-light" title="{{ __('Download PDF') }}"><i class="ri-download-2-line"></i></a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
