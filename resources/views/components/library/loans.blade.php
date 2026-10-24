{{--
    A borrower's library loans: books out now, fines owed and earlier loans.
    :data from StudentOverview::library().
--}}
@use('App\Services\Library\Library')
@use('App\Support\Billing\Money')

@props(['data'])

@php
    $format = tenant()->setting('date_format');
    $today = tenant()->today();
    $rules = Library::settings();
@endphp

<div>
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <x-stat-card :label="__('Books out')" :value="$data['out']->count()" icon="ri-book-2-line" color="info"
                         :hint="__(':days-day loans', ['days' => $rules['loan_days']])" />
        </div>
        <div class="col-md-4">
            <x-stat-card :label="__('Overdue')" :value="$data['overdue']" icon="ri-alarm-warning-line" :color="$data['overdue'] > 0 ? 'danger' : 'success'"
                         :hint="$rules['fine_per_day'] > 0 ? __(':amount fine a day late', ['amount' => Money::school($rules['fine_per_day'])]) : null" />
        </div>
        <div class="col-md-4">
            <x-stat-card :label="__('Fines owed')" :value="Money::school($data['owed'])" icon="ri-money-dollar-circle-line" :color="$data['owed'] > 0 ? 'warning' : 'secondary'"
                         :hint="$data['owed'] > 0 ? __('Pay at the library') : null" />
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h5 class="card-title mb-0">{{ __('Books out now') }}</h5></div>
        @if ($data['out']->isEmpty())
            <x-empty-state icon="ri-book-open-line" :title="__('No books out')" :message="__('Books borrowed from the library appear here with their due dates.')" class="py-6" />
        @else
            <ul class="list-group list-group-flush">
                @foreach ($data['out'] as $issue)
                    @php($late = $issue->daysLate($today))
                    <li class="list-group-item d-flex align-items-center gap-3" wire:key="loan-{{ $issue->id }}">
                        <span @class(['avatar-item avatar avatar-title fs-5 flex-shrink-0', 'bg-danger-subtle text-danger' => $late > 0, 'bg-info-subtle text-info' => $late === 0])><i class="ri-book-2-line"></i></span>
                        <div class="min-w-0 flex-grow-1">
                            <span class="fw-medium d-block text-truncate">{{ $issue->book?->title }}</span>
                            <small class="text-muted">{{ collect([$issue->book?->author, __('borrowed :date', ['date' => $issue->issued_on->format($format)])])->filter()->implode(' · ') }}</small>
                        </div>
                        <div class="text-end text-nowrap">
                            <span @class(['d-block fw-medium', 'text-danger' => $late > 0])>{{ __('Due :date', ['date' => $issue->due_on->format($format)]) }}</span>
                            @if ($late > 0)
                                <small class="text-danger">{{ trans_choice(':count day overdue|:count days overdue', $late, ['count' => $late]) }}</small>
                            @else
                                @php($left = App\Models\BookIssue::daysBetween($today, $issue->due_on))
                                <small class="text-muted">{{ $left === 0 ? __('due today') : trans_choice(':count day left|:count days left', $left, ['count' => $left]) }}</small>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="card mb-0">
        <div class="card-header"><h5 class="card-title mb-0">{{ __('Earlier loans') }}</h5></div>
        @if ($data['history']->isEmpty())
            <x-empty-state icon="ri-history-line" :title="__('Nothing returned yet')" class="py-6" />
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="bg-light bg-opacity-30">
                        <tr>
                            <th scope="col" class="ps-4">{{ __('Book') }}</th>
                            <th scope="col">{{ __('Borrowed') }}</th>
                            <th scope="col">{{ __('Back') }}</th>
                            <th scope="col" class="pe-4 text-end">{{ __('Fine') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['history'] as $issue)
                            <tr wire:key="history-{{ $issue->id }}">
                                <td class="ps-4">
                                    <span class="fw-medium d-block">{{ $issue->book?->title }}</span>
                                    @if ($issue->status !== App\Enums\BookIssueStatus::Returned)
                                        <span class="badge bg-{{ $issue->status->color() }}-subtle text-{{ $issue->status->color() }}">{{ $issue->status->label() }}</span>
                                    @endif
                                </td>
                                <td class="text-nowrap">{{ $issue->issued_on->format($format) }}</td>
                                <td class="text-nowrap">
                                    {{ $issue->returned_on?->format($format) ?? '—' }}
                                    @if ($issue->daysLate() > 0)
                                        <small class="d-block text-danger">{{ trans_choice(':count day late|:count days late', $issue->daysLate(), ['count' => $issue->daysLate()]) }}</small>
                                    @endif
                                </td>
                                <td class="pe-4 text-end">
                                    @if ($issue->fine_status !== App\Enums\FineStatus::None)
                                        <span class="d-block">{{ Money::school($issue->fine_amount) }}</span>
                                        <span class="badge bg-{{ $issue->fine_status->color() }}-subtle text-{{ $issue->fine_status->color() }}">{{ $issue->fine_status->label() }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
