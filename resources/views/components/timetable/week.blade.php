{{--
    A week of periods: one column per school day, one row per period, with
    breaks between periods shown as thin rows.

        <x-timetable.week :periods="$periods" :days="$days" :entries="$week" />

    :entries  "day-period" => Timetable (a class's week) or a collection of
              them (a teacher's week, where two classes could share a period)
    mode      "class": subject, teacher and room; "teacher": class, subject and room
    :edit     ['classId' => id, 'sectionId' => id|null] makes each cell open the slot form
    :today    ISO weekday to highlight
--}}
@use('App\Services\Timetable\BellSchedule')
@use('Illuminate\Support\Collection')

@props(['periods', 'days', 'entries', 'mode' => 'class', 'edit' => null, 'today' => null])

@php
    $palette = ['primary', 'success', 'info', 'warning', 'danger', 'secondary'];
    $periods = collect($periods)->values();
@endphp

<div {{ $attributes->class('table-responsive') }}>
    <table class="table table-bordered timetable-grid mb-0">
        <thead class="bg-light bg-opacity-30">
            <tr>
                <th scope="col" class="text-muted fw-medium">{{ __('Period') }}</th>
                @foreach ($days as $day)
                    <th scope="col" @class(['text-center fw-semibold', 'is-today text-primary' => $day === $today])>
                        <span class="d-none d-lg-inline">{{ BellSchedule::dayName($day) }}</span>
                        <span class="d-lg-none">{{ BellSchedule::dayName($day, short: true) }}</span>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($periods as $index => $period)
                @if ($index > 0 && ($gap = BellSchedule::gapMinutes($periods[$index - 1], $period)) >= 5)
                    <tr>
                        <td colspan="{{ count($days) + 1 }}" class="text-center text-muted fs-12 py-1 bg-light bg-opacity-50" style="height: auto">
                            <i class="ri-cup-line me-1"></i>{{ __('Break · :minutes min', ['minutes' => $gap]) }}
                        </td>
                    </tr>
                @endif
                <tr wire:key="period-{{ $period['number'] }}">
                    <th scope="row" class="fw-normal">
                        <span class="fw-semibold">{{ __('Period :number', ['number' => $period['number']]) }}</span>
                        <small class="d-block text-muted">{{ BellSchedule::label($period) }}</small>
                    </th>
                    @foreach ($days as $day)
                        @php
                            $cell = $entries->get($day.'-'.$period['number']);
                            $inCell = $cell instanceof Collection ? $cell : collect($cell ? [$cell] : []);
                        @endphp
                        <td @class(['is-today' => $day === $today]) wire:key="cell-{{ $day }}-{{ $period['number'] }}">
                            @if ($edit)
                                <button type="button" @class(['slot', 'slot-empty' => $inCell->isEmpty()])
                                        wire:click="$dispatch('open-modal', @js(['component' => 'forms.timetable-slot', 'params' => [...$edit, 'day' => $day, 'period' => $period['number']]]))"
                                        aria-label="{{ __(':day, period :number', ['day' => BellSchedule::dayName($day), 'number' => $period['number']]) }}">
                            @endif
                            @forelse ($inCell as $slot)
                                @php($color = $palette[($slot->subjectClass?->subject_id ?? 0) % count($palette)])
                                <div @class(['rounded px-2 py-1', 'bg-'.$color.'-subtle', 'mt-1' => ! $loop->first]) @unless ($edit) style="min-height: 100%" @endunless>
                                    <span class="d-block fw-semibold text-{{ $color }} text-truncate">
                                        {{ $mode === 'teacher' ? $slot->schoolClass?->name.($slot->section ? ' · '.$slot->section->name : '') : $slot->subjectClass?->subject?->name }}
                                    </span>
                                    <small class="d-block text-truncate">
                                        {{ $mode === 'teacher' ? $slot->subjectClass?->subject?->name : ($slot->subjectClass?->teacher?->user?->name ?? __('No teacher')) }}
                                    </small>
                                    @if ($slot->classroom)
                                        <small class="d-block text-muted text-truncate"><i class="ri-door-open-line me-1"></i>{{ $slot->classroom->label() }}</small>
                                    @endif
                                </div>
                            @empty
                                @if ($edit)
                                    <i class="ri-add-line fs-5" aria-hidden="true"></i>
                                @endif
                            @endforelse
                            @if ($edit)
                                </button>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
