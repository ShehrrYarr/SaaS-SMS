{{--
    Homework still to hand in, soonest due first.
    :items from StudentOverview::upcomingHomework(); :portal is student or parent.
--}}
@use('App\Enums\SubmissionStatus')

@props(['items', 'portal'])

<div {{ $attributes->class('card mb-0 h-100') }}>
    <div class="card-header hstack gap-2">
        <h5 class="card-title mb-0 flex-grow-1">{{ __('Homework to do') }}</h5>
        <a href="{{ route($portal.'.homework.index') }}" wire:navigate class="fs-13">{{ __('All homework') }}</a>
    </div>
    @if ($items->isEmpty())
        <x-empty-state icon="ri-book-open-line" :title="__('All caught up')" :message="__('No homework waiting to be handed in.')" class="py-6" />
    @else
        <ul class="list-group list-group-flush">
            @foreach ($items as $homework)
                @php
                    $overdue = $homework->isPastDue();
                    $returned = $homework->submissions->first()?->status === SubmissionStatus::Returned;
                @endphp
                <li class="list-group-item" wire:key="todo-{{ $homework->id }}">
                    <a href="{{ route($portal.'.homework.show', $homework->id) }}" wire:navigate class="fw-medium text-body d-block text-truncate">{{ $homework->title }}</a>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <small class="text-muted">{{ $homework->subjectClass?->subject?->name }}</small>
                        <small @class(['ms-auto', 'text-danger' => $overdue, 'text-muted' => ! $overdue])>
                            @if ($returned)<span class="badge bg-primary-subtle text-primary me-1">{{ __('Sent back') }}</span>@endif
                            {{ $overdue ? __('Overdue · :date', ['date' => $homework->dueLabel()]) : __('Due :date', ['date' => $homework->dueLabel()]) }}
                        </small>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
