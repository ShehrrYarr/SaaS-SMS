{{--
    Fee dues: outstanding, overdue and the next invoice due.
    :summary from StudentOverview::fees(); :portal is student or parent.
--}}
@use('App\Support\Billing\Money')

@props(['summary', 'portal'])

@php($format = tenant()->setting('date_format'))

<div {{ $attributes->class('card mb-0 h-100') }}>
    <div class="card-header hstack gap-2">
        <h5 class="card-title mb-0 flex-grow-1">{{ __('Fee dues') }}</h5>
        <a href="{{ route($portal.'.fees') }}" wire:navigate class="fs-13">{{ __('Invoices & receipts') }}</a>
    </div>
    <div class="card-body">
        @if ($summary['outstanding'] <= 0)
            <div class="hstack gap-3">
                <span class="avatar-item avatar avatar-title bg-success-subtle text-success fs-4 flex-shrink-0"><i class="ri-checkbox-circle-line"></i></span>
                <div>
                    <h6 class="mb-0">{{ __('All paid up') }}</h6>
                    <small class="text-muted">{{ __('Paid this year: :amount', ['amount' => Money::school($summary['paid_this_year'])]) }}</small>
                </div>
            </div>
        @else
            <div class="row g-3">
                <div class="col-6">
                    <small class="text-muted d-block">{{ __('Outstanding') }}</small>
                    <span class="fs-4 fw-semibold">{{ Money::school($summary['outstanding']) }}</span>
                </div>
                <div class="col-6">
                    <small class="text-muted d-block">{{ __('Overdue') }}</small>
                    <span @class(['fs-4 fw-semibold', 'text-danger' => $summary['overdue'] > 0])>{{ Money::school($summary['overdue']) }}</span>
                </div>
            </div>
            @if ($summary['next'])
                @php($next = $summary['next'])
                <div class="border rounded p-3 mt-3 d-flex align-items-center gap-3">
                    <i class="ri-bill-line fs-4 text-warning"></i>
                    <div class="min-w-0 flex-grow-1">
                        <span class="fw-medium d-block">{{ $next->invoice_number }} <span class="text-muted fw-normal">{{ $next->periodLabel() }}</span></span>
                        <small @class(['text-danger' => $next->due_date->lt(tenant()->today()), 'text-muted' => ! $next->due_date->lt(tenant()->today())])>
                            {{ __(':amount due :date', ['amount' => Money::school($next->balanceAmount()), 'date' => $next->due_date->format($format)]) }}
                        </small>
                    </div>
                    <a href="{{ route($portal.'.invoices.document', $next->id) }}" target="_blank" class="btn btn-sm btn-light" title="{{ __('View invoice') }}"><i class="ri-printer-line"></i></a>
                </div>
            @endif
            <p class="text-muted fs-12 mt-3 mb-0">{{ __('Pay at the school office; your receipt appears here.') }}</p>
        @endif
    </div>
</div>
