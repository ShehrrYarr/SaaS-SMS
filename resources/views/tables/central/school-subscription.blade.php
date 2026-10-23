@php
    $state = $row->subscriptionState();
    $endsAt = $row->accessEndsAt();
@endphp
<span class="badge bg-{{ $state->color() }}-subtle text-{{ $state->color() }}">{{ $state->label() }}</span>
@if ($endsAt)
    <small class="d-block text-muted mt-1">
        {{ $endsAt->isFuture() || $endsAt->isToday() ? __('until :date', ['date' => $endsAt->format('d M Y')]) : __('ended :date', ['date' => $endsAt->format('d M Y')]) }}
    </small>
@endif
