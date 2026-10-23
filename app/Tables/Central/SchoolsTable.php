<?php

namespace App\Tables\Central;

use App\Enums\SchoolStatus;
use App\Enums\SubscriptionState;
use App\Models\Plan;
use App\Models\School;
use App\Services\Schools\SchoolLifecycle;
use App\Tables\Action;
use App\Tables\Column;
use App\Tables\Filter;
use Illuminate\Database\Eloquent\Builder;

class SchoolsTable extends CentralTable
{
    public function permission(): ?string
    {
        return 'manage-schools';
    }

    public function query(): Builder
    {
        return School::query()->with('plan')->withCount(['activeStudents as students_count', 'staff']);
    }

    public function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    public function columns(): array
    {
        return [
            Column::make('name', __('School'))->sortable()->searchable()->view('tables.central.school-name'),
            Column::make('slug', __('Address'))->searchable()->hiddenOnMobile()
                ->format(fn (string $slug) => '/'.$slug),
            Column::make('plan.name', __('Plan'))->hiddenOnMobile(),
            Column::make('students_count', __('Students'))->sortable()->view('tables.central.school-usage'),
            Column::make('subscription_ends_at', __('Subscription'))->sortable()->view('tables.central.school-subscription'),
            Column::make('status', __('Status'))->sortable()->badge(),
            Column::make('created_at', __('Created'))->sortable()->date()->hiddenOnMobile(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('status', __('Status'))->options(SchoolStatus::class),
            Filter::make('subscription', __('Subscription'))
                ->options(SubscriptionState::class)
                ->query(fn (Builder $query, string $state) => $query->whereSubscriptionState(SubscriptionState::from($state))),
            Filter::make('plan_id', __('Plan'))
                ->options(Plan::query()->withTrashed()->ordered()->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()),
        ];
    }

    public function actions(): array
    {
        return [
            Action::make('view', __('Open'))
                ->icon('ri-eye-line')
                ->url(fn (School $school) => route('central.schools.show', $school)),

            Action::edit('forms.central.school', 'school', 'manage-schools'),

            Action::make('suspend', __('Suspend'))
                ->icon('ri-pause-circle-line')
                ->color('warning')
                ->can('manage-schools')
                ->visible(fn (School $school) => $school->isActive())
                ->modal('forms.central.suspend-school', fn (School $school) => ['school' => $school->id]),

            Action::make('reactivate', __('Reactivate'))
                ->icon('ri-play-circle-line')
                ->color('success')
                ->can('manage-schools')
                ->visible(fn (School $school) => $school->isSuspended())
                ->confirm(__('Its users will be able to sign in again.'), __('Reactivate this school?'))
                ->handle(fn (School $school) => app(SchoolLifecycle::class)->reactivate($school, auth()->user()))
                ->success(fn (School $school) => __(':name has been reactivated.', ['name' => $school->name])),
        ];
    }

    public function searchPlaceholder(): string
    {
        return __('Search by name or address…');
    }

    public function emptyTitle(): string
    {
        return __('No schools yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Create the first school to give it its own portal and administrator account.');
    }
}
