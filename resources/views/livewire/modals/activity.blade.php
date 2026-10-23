<?php

/*
 * One activity-log entry: who, what, when, and the values that changed.
 *     $dispatch('open-modal', { component: 'modals.activity', params: { activity: 81 }, size: 'lg' })
 */

use App\Models\Activity;
use App\Tables\ActivityLogTable;
use Illuminate\Support\Str;

use function Livewire\Volt\{computed, mount, state};

state(['activity' => null])->locked();

mount(function (int $activity) {
    $this->authorize('activity-log.view');

    $this->activity = Activity::query()->findOrFail($activity)->id;
});

$entry = computed(fn () => Activity::query()->with(['causer', 'subject'])->findOrFail($this->activity));

?>

@php
    $entry = $this->entry;
    $school = tenant();
    $properties = $entry->properties?->toArray() ?? [];
    $new = $properties['attributes'] ?? [];
    $old = $properties['old'] ?? [];
    $extra = array_diff_key($properties, ['attributes' => true, 'old' => true]);
    $show = fn ($value) => match (true) {
        $value === null => '—',
        is_bool($value) => $value ? __('Yes') : __('No'),
        is_array($value) && array_is_list($value) && collect($value)->every(fn ($item) => is_scalar($item)) => $value ? implode(', ', $value) : '—',
        is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        default => (string) $value,
    };
@endphp

<div class="modal-form">
    <div class="modal-header">
        <div>
            <h5 class="modal-title">{{ __(Str::ucfirst($entry->description)) }}</h5>
            <p class="text-muted fs-13 mb-0">
                {{ $school->localTime($entry->created_at)->format($school->setting('date_format').', '.$school->setting('time_format')) }}
                · {{ $entry->causer?->name ?? __('System') }}
            </p>
        </div>
        <button type="button" class="btn btn-text-primary icon-btn-sm" data-bs-dismiss="modal" aria-label="{{ __('Close') }}">
            <i class="ri-close-large-line fw-semibold"></i>
        </button>
    </div>

    <div class="modal-body">
        @if ($entry->subject_type)
            <p class="mb-3"><span class="text-muted">{{ __('Record') }}:</span> {{ ActivityLogTable::subjectLabel($entry) }}</p>
        @endif

        @if ($new || $old)
            <div class="table-responsive border rounded-2 mb-3">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="bg-light bg-opacity-30">
                        <tr><th scope="col" class="ps-3">{{ __('Field') }}</th><th scope="col">{{ __('Before') }}</th><th scope="col">{{ __('After') }}</th></tr>
                    </thead>
                    <tbody>
                        @foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field)
                            <tr>
                                <th scope="row" class="ps-3 fw-medium">{{ Str::headline($field) }}</th>
                                <td class="text-muted text-break">{{ $show($old[$field] ?? null) }}</td>
                                <td class="text-break">{{ $show($new[$field] ?? null) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($extra)
            <dl class="detail-list mb-0">
                @foreach ($extra as $key => $value)
                    <dt>{{ Str::headline($key) }}</dt>
                    <dd class="text-break">{{ $show($value) }}</dd>
                @endforeach
            </dl>
        @endif

        @if (! $new && ! $old && ! $extra)
            <p class="text-muted mb-0">{{ __('No further details were recorded.') }}</p>
        @endif
    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Close') }}</button>
    </div>
</div>
