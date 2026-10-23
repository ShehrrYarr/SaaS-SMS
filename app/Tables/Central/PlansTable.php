<?php

namespace App\Tables\Central;

use App\Models\Plan;
use App\Tables\Action;
use App\Tables\ActionFailed;
use App\Tables\Column;
use App\Tables\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class PlansTable extends CentralTable
{
    public function permission(): ?string
    {
        return 'manage-subscriptions';
    }

    public function query(): Builder
    {
        return Plan::query()->withCount('schools');
    }

    public function defaultSort(): array
    {
        return ['sort_order', 'asc'];
    }

    public function columns(): array
    {
        return [
            Column::make('name', __('Plan'))->sortable()->searchable()
                ->format(fn (string $name, Plan $plan) => new HtmlString(
                    '<span class="fw-medium text-body">'.e($name).'</span>'
                    .($plan->description ? '<small class="d-block text-muted text-truncate" style="max-width: 18rem">'.e($plan->description).'</small>' : '')
                )),
            Column::make('price', __('Price'))->sortable()
                ->format(fn ($price, Plan $plan) => new HtmlString(
                    '<span class="fw-medium">'.e($plan->formattedPrice()).'</span> <small class="text-muted">'.e($plan->billing_cycle->per()).'</small>'
                )),
            Column::make('max_students', __('Students'))->sortable()->hiddenOnMobile()
                ->format(fn (?int $max) => $max === null ? __('Unlimited') : number_format($max)),
            Column::make('max_staff', __('Staff'))->sortable()->hiddenOnMobile()
                ->format(fn (?int $max) => $max === null ? __('Unlimited') : number_format($max)),
            Column::make('features', __('Features'))->hiddenOnMobile()
                ->format(fn ($features, Plan $plan) => $plan->includesAllFeatures()
                    ? __('All features')
                    : trans_choice('{0} Core only|{1} :count feature|[2,*] :count features', count($plan->features), ['count' => count($plan->features)])),
            Column::make('trial_days', __('Trial'))->sortable()->hiddenOnMobile()
                ->format(fn (int $days) => $days > 0 ? trans_choice('{1} :count day|[2,*] :count days', $days, ['count' => $days]) : __('None')),
            Column::make('schools_count', __('Schools'))->sortable(),
            Column::make('is_active', __('Offered'))->sortable()->boolean(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('is_active', __('Offered'))->options(['1' => __('Offered'), '0' => __('Retired')]),
        ];
    }

    public function actions(): array
    {
        return [
            Action::edit('forms.central.plan', 'plan', 'manage-subscriptions')
                ->modal('forms.central.plan', fn (Plan $plan) => ['plan' => $plan->id], 'lg'),

            Action::delete('manage-subscriptions', __('Schools can no longer be put on this plan.'))
                ->handle(function (Plan $plan) {
                    $schools = $plan->schools()->count();

                    if ($schools > 0) {
                        throw new ActionFailed(trans_choice(
                            '{1} :name is used by :count school. Move it to another plan, or stop offering this one instead.|[2,*] :name is used by :count schools. Move them to another plan, or stop offering this one instead.',
                            $schools,
                            ['name' => $plan->name, 'count' => $schools],
                        ));
                    }

                    $plan->delete();
                })
                ->success(fn (Plan $plan) => __(':name deleted.', ['name' => $plan->name])),
        ];
    }

    public function searchPlaceholder(): string
    {
        return __('Search plans…');
    }

    public function emptyTitle(): string
    {
        return __('No plans yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Create a plan to set prices, limits and features for schools.');
    }
}
