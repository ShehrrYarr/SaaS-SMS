<?php

namespace App\Tables;

use App\Enums\AllocationStatus;
use App\Models\StudentTransport;
use App\Models\TransportRoute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Students on school transport. Params:
 *  - ['route' => id]: one route's riders;
 *  - ['student' => id]: one student's history.
 * Shows current assignments unless the status filter says otherwise.
 */
class StudentTransportsTable extends Table
{
    public function permission(): ?string
    {
        return 'transport.view';
    }

    public function query(): Builder
    {
        return StudentTransport::query()
            ->with(['student' => fn ($query) => $query->withTrashed()->with(['user', 'schoolClass', 'section']), 'route' => fn ($query) => $query->withTrashed()])
            ->when($this->params['route'] ?? null, fn (Builder $query, $route) => $query->where('transport_route_id', (int) $route))
            ->when($this->params['student'] ?? null, fn (Builder $query, $student) => $query->where('student_profile_id', (int) $student));
    }

    public function defaultSort(): array
    {
        return ['start_date', 'desc'];
    }

    public function columns(): array
    {
        $format = tenant()?->setting('date_format') ?? 'd M Y';

        return array_values(array_filter([
            isset($this->params['student']) ? null : Column::make('student', __('Student'))->virtual()->searchable('student.user.name')
                ->format(fn ($value, StudentTransport $row) => Cells::person(
                    $row->student?->user,
                    collect([$row->student?->admission_number, $row->student?->placementLabel()])->filter()->implode(' · '),
                    $row->student && auth()->user()?->can('students.view') ? route('admin.students.show', $row->student->id) : null,
                )),
            isset($this->params['route']) ? null : Column::make('transport_route_id', __('Route'))
                ->format(fn ($value, StudentTransport $row) => $row->route ? new HtmlString(
                    '<a href="'.e(route('admin.transport.routes.show', $row->route->id)).'" wire:navigate class="text-body">'.e($row->route->name).'</a>',
                ) : null),
            Column::make('pickup_point', __('Stop'))->sortable()
                ->format(fn (?string $stop, StudentTransport $row) => $stop ? new HtmlString(
                    '<span class="d-block">'.e($stop).'</span>'
                    .(($time = $row->route?->pickupTime($stop)) ? '<small class="text-muted"><i class="ri-time-line me-1"></i>'.e($time).'</small>' : ''),
                ) : null),
            Column::make('start_date', __('Since'))->sortable()
                ->format(fn ($value, StudentTransport $row) => new HtmlString(
                    '<span class="text-nowrap">'.e($row->start_date->format($format)).'</span>'
                    .($row->end_date ? '<small class="d-block text-muted text-nowrap">'.e(__('until :date', ['date' => $row->end_date->format($format)])).'</small>' : ''),
                )),
            Column::make('status', __('Status'))->sortable()->badge(),
        ]));
    }

    public function filters(): array
    {
        return array_values(array_filter([
            Filter::make('status', __('Status'))->options(AllocationStatus::options()),
            isset($this->params['route']) || isset($this->params['student']) ? null : Filter::make('transport_route_id', __('Route'))
                ->options(TransportRoute::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()),
        ]));
    }

    public function defaultFilters(): array
    {
        // Current riders; one student's history shows everything.
        return isset($this->params['student']) ? [] : ['status' => AllocationStatus::Active->value];
    }

    public function actions(): array
    {
        return [
            Action::make('change', __('Change route or stop'))
                ->icon('ri-arrow-left-right-line')
                ->modal('forms.student-transport', fn (StudentTransport $row) => ['assignment' => $row->id])
                ->can('transport.manage')
                ->visible(fn (StudentTransport $row) => $row->isActive()),
            Action::make('end', __('End transport'))
                ->icon('ri-stop-circle-line')
                ->color('danger')
                ->modal('forms.end-service', fn (StudentTransport $row) => ['kind' => 'transport', 'id' => $row->id])
                ->can('transport.manage')
                ->visible(fn (StudentTransport $row) => $row->isActive()),
        ];
    }

    protected function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->whereHas('student', fn (Builder $student) => $student
            ->where('admission_number', 'like', $term)
            ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $term)));
    }

    public function searchPlaceholder(): string
    {
        return __('Search by student…');
    }

    public function emptyTitle(): string
    {
        return match (true) {
            isset($this->params['route']) => __('Nobody rides this route yet'),
            isset($this->params['student']) => __('Not on school transport'),
            default => __('No students on school transport'),
        };
    }
}
