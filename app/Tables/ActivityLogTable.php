<?php

namespace App\Tables;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The school's audit trail (tenant-scoped through Activity's BelongsToSchool).
 */
class ActivityLogTable extends Table
{
    public int $perPage = 25;

    public function permission(): ?string
    {
        return 'activity-log.view';
    }

    public function query(): Builder
    {
        return Activity::query()->with(['causer', 'subject']);
    }

    public function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    public function columns(): array
    {
        return [
            Column::make('created_at', __('When'))->sortable(fn (Builder $query, string $direction) => $query->orderBy('created_at', $direction)->orderBy('id', $direction))->dateTime(),
            Column::make('causer', __('Who'))
                ->format(fn ($causer) => $causer instanceof User
                    ? new HtmlString('<span class="fw-medium text-body">'.e($causer->name).'</span>')
                    : new HtmlString('<span class="text-muted">'.e(__('System')).'</span>')),
            Column::make('description', __('What'))->searchable()
                ->format(fn (string $description, Activity $activity) => new HtmlString(
                    e(__(Str::ucfirst($description)))
                    .($activity->subject_type ? '<small class="d-block text-muted">'.e(static::subjectLabel($activity)).'</small>' : '')
                )),
            Column::make('log_name', __('Area'))->sortable()->hiddenOnMobile()
                ->format(fn (?string $log) => $log ? new HtmlString('<span class="badge bg-light text-body border">'.e(Str::headline($log)).'</span>') : null),
        ];
    }

    public function filters(): array
    {
        $areas = Activity::query()->whereNotNull('log_name')->distinct()->orderBy('log_name')->pluck('log_name');

        return [
            Filter::make('log_name', __('Area'))->options($areas->mapWithKeys(fn ($log) => [$log => Str::headline($log)])->all()),
            Filter::make('causer_id', __('Who'))->options(
                User::query()->whereIn('id', Activity::query()->where('causer_type', (new User)->getMorphClass())->distinct()->select('causer_id'))
                    ->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()
            ),
        ];
    }

    public function actions(): array
    {
        return [
            Action::make('details', __('Details'))
                ->icon('ri-file-search-line')
                ->modal('modals.activity', fn (Activity $activity) => ['activity' => $activity->id], 'lg'),
        ];
    }

    /**
     * "Notice: Exam timetable", "Role #4"...
     */
    public static function subjectLabel(Activity $activity): string
    {
        $type = Str::headline($activity->subject_type);
        $subject = $activity->subject;
        $name = $subject ? ($subject->getAttributes()['name'] ?? $subject->getAttributes()['title'] ?? null) : null;

        return $name ? "{$type}: {$name}" : "{$type} #{$activity->subject_id}";
    }

    public function searchPlaceholder(): string
    {
        return __('Search activity…');
    }

    public function emptyTitle(): string
    {
        return __('No activity recorded yet');
    }
}
