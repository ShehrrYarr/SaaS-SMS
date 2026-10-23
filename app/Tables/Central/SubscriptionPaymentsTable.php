<?php

namespace App\Tables\Central;

use App\Enums\PaymentMethod;
use App\Models\SubscriptionPayment;
use App\Services\Billing\SubscriptionManager;
use App\Support\Billing\Money;
use App\Tables\Action;
use App\Tables\Column;
use App\Tables\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Recorded SaaS payments, across all schools or for one (params: school_id).
 */
class SubscriptionPaymentsTable extends CentralTable
{
    public function permission(): ?string
    {
        return 'manage-subscriptions';
    }

    public function query(): Builder
    {
        return SubscriptionPayment::query()
            ->with(['school' => fn ($query) => $query->withTrashed(), 'plan', 'recorder'])
            ->when($this->params['school_id'] ?? null, fn (Builder $query, $schoolId) => $query->where('school_id', $schoolId));
    }

    public function defaultSort(): array
    {
        return ['paid_on', 'desc'];
    }

    private function forOneSchool(): bool
    {
        return isset($this->params['school_id']);
    }

    public function columns(): array
    {
        return array_values(array_filter([
            Column::make('paid_on', __('Paid on'))->sortable()->date('d M Y'),
            $this->forOneSchool() ? null : Column::make('school.name', __('School'))->searchable()
                ->format(fn (?string $name, SubscriptionPayment $payment) => $payment->school && ! $payment->school->trashed()
                    ? new HtmlString('<a href="'.e(route('central.schools.show', $payment->school)).'" wire:navigate class="fw-medium">'.e($name).'</a>')
                    : $name),
            Column::make('plan.name', __('Plan'))->hiddenOnMobile(),
            Column::make('period_start', __('Period'))->sortable()
                ->format(fn ($start, SubscriptionPayment $payment) => $payment->period_start->format('d M Y').' – '.$payment->period_end->format('d M Y')),
            Column::make('payment_method', __('Method'))->hiddenOnMobile(),
            Column::make('reference', __('Reference'))->searchable()->hiddenOnMobile(),
            Column::make('amount', __('Amount'))->sortable()->alignEnd()
                ->format(fn ($amount) => new HtmlString('<span class="fw-medium">'.e(Money::platform($amount)).'</span>')),
        ]));
    }

    public function filters(): array
    {
        return [
            Filter::make('payment_method', __('Method'))->options(PaymentMethod::class),
        ];
    }

    public function actions(): array
    {
        return [
            Action::delete('manage-subscriptions', __('The school\'s paid-until date is recalculated from its remaining payments.'))
                ->handle(fn (SubscriptionPayment $payment) => app(SubscriptionManager::class)->deletePayment($payment, auth()->user()))
                ->success(__('Payment deleted.')),
        ];
    }

    public function searchPlaceholder(): string
    {
        return $this->forOneSchool() ? __('Search by reference…') : __('Search by school or reference…');
    }

    public function emptyTitle(): string
    {
        return __('No payments recorded');
    }

    public function emptyMessage(): ?string
    {
        return __('Payments you record extend the school\'s subscription.');
    }
}
