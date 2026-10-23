{{--
    Today's periods as a list, with the one in progress marked.

        <x-timetable.today :entries="$todaySlots" mode="teacher" />

    :entries Timetable slots of today, in time order
    mode     "class": subject and teacher; "teacher": class and subject
--}}
@props(['entries', 'mode' => 'class', 'title' => null])

@php
    $now = tenant()->localTime(now())->format('H:i:s');
    $today = tenant()->today();
@endphp

<div {{ $attributes->class('card mb-0') }}>
    <div class="card-header hstack gap-2">
        <h5 class="card-title mb-0 flex-grow-1">{{ $title ?? __('Today') }}</h5>
        <small class="text-muted">{{ $today->translatedFormat('l, j F') }}</small>
    </div>
    @if ($entries->isEmpty())
        <x-empty-state icon="ri-sun-line" :title="__('No classes today')" :message="__('Enjoy the day off.')" class="py-6" />
    @else
        <ul class="list-group list-group-flush">
            @foreach ($entries as $slot)
                @php
                    $current = $slot->start_time <= $now && $slot->end_time > $now;
                    $done = $slot->end_time <= $now;
                @endphp
                <li @class(['list-group-item hstack gap-3', 'bg-primary-subtle' => $current, 'text-muted' => $done]) wire:key="today-{{ $slot->id }}">
                    <div class="text-nowrap fs-13" style="min-width: 5.5rem">
                        <span class="fw-semibold d-block">{{ __('Period :number', ['number' => $slot->period_number]) }}</span>
                        <small class="text-muted">{{ $slot->timeLabel() }}</small>
                    </div>
                    <div class="min-w-0 flex-grow-1">
                        <span class="fw-medium d-block text-truncate">
                            {{ $mode === 'teacher' ? $slot->subjectClass?->subject?->name.' · '.$slot->schoolClass?->name.($slot->section ? ' '.$slot->section->name : '') : $slot->subjectClass?->subject?->name }}
                        </span>
                        <small class="text-muted text-truncate d-block">
                            @if ($mode !== 'teacher'){{ $slot->subjectClass?->teacher?->user?->name ?? __('No teacher') }}@endif
                            @if ($slot->classroom){{ $mode !== 'teacher' ? ' · ' : '' }}<i class="ri-door-open-line"></i> {{ $slot->classroom->label() }}@endif
                        </small>
                    </div>
                    @if ($current)
                        <span class="badge bg-primary">{{ __('Now') }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
