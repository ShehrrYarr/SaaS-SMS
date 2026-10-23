{{--
    What teachers have written lately: report-card remarks and homework
    feedback. :remarks from StudentOverview::remarks().
--}}
@props(['remarks'])

@php($format = tenant()->setting('date_format'))

<div {{ $attributes->class('card mb-0 h-100') }}>
    <div class="card-header"><h5 class="card-title mb-0">{{ __('Teacher remarks') }}</h5></div>
    @if ($remarks->isEmpty())
        <x-empty-state icon="ri-chat-quote-line" :title="__('No remarks yet')" :message="__('Comments on report cards and homework appear here.')" class="py-6" />
    @else
        <ul class="list-group list-group-flush">
            @foreach ($remarks as $remark)
                <li class="list-group-item">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <i @class(['ri-award-line text-success' => $remark['type'] === 'exam', 'ri-book-open-line text-primary' => $remark['type'] === 'homework'])></i>
                        <small class="fw-medium text-truncate flex-grow-1">{{ $remark['title'] }}</small>
                        @if ($remark['at'])<small class="text-muted text-nowrap">{{ tenant()->localTime($remark['at'])->format($format) }}</small>@endif
                    </div>
                    <p class="mb-0 fs-13 text-pre-line">{{ Str::limit($remark['text'], 240) }}</p>
                    @if ($remark['by'])<small class="text-muted">— {{ $remark['by'] }}</small>@endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
