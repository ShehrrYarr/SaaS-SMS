<?php

namespace App\Tables;

use App\Models\TransportRoute;
use App\Services\Transport\Transport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Bus routes: vehicle, stops, fare and how full they are.
 */
class TransportRoutesTable extends Table
{
    public function permission(): ?string
    {
        return 'transport.view';
    }

    public function query(): Builder
    {
        return TransportRoute::query()->with('vehicle')->withCount('activeAssignments');
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function columns(): array
    {
        return [
            Column::make('name', __('Route'))->sortable()->searchable()
                ->format(fn (string $name, TransportRoute $route) => new HtmlString(
                    '<a href="'.e(route('admin.transport.routes.show', $route->id)).'" wire:navigate class="fw-medium text-body d-block">'.e($name).'</a>'
                    .($route->endpoints() ? '<small class="text-muted">'.e($route->endpoints()).'</small>' : ''),
                )),
            Column::make('transport_vehicle_id', __('Vehicle'))
                ->format(fn ($value, TransportRoute $route) => $route->vehicle ? new HtmlString(
                    '<span class="d-block">'.e($route->vehicle->vehicle_number).'</span>'
                    .($route->vehicle->driver_name ? '<small class="text-muted">'.e($route->vehicle->driver_name).'</small>' : ''),
                ) : new HtmlString('<span class="text-muted">'.e(__('None yet')).'</span>')),
            Column::make('stops', __('Stops'))->hiddenOnMobile()
                ->format(fn ($value, TransportRoute $route) => $route->stopList()->count() ?: null),
            Column::make('fare', __('Monthly fare'))->sortable()->money(),
            Column::make('active_assignments_count', __('Students'))->sortable()
                ->format(function (int $riders, TransportRoute $route) {
                    $seats = $route->seats();

                    if ($seats === null) {
                        return $riders;
                    }

                    $color = $riders >= $seats ? 'danger' : ($riders >= $seats * 0.8 ? 'warning' : 'success');

                    return new HtmlString('<span class="badge bg-'.$color.'-subtle text-'.$color.'">'.e(__(':riders of :seats', ['riders' => $riders, 'seats' => $seats])).'</span>');
                }),
        ];
    }

    public function actions(): array
    {
        return [
            Action::make('open', __('Students & stops'))
                ->icon('ri-route-line')
                ->url(fn (TransportRoute $route) => route('admin.transport.routes.show', $route->id)),
            Action::edit('forms.transport-route', 'route', 'transport.manage')->modal('forms.transport-route', fn (TransportRoute $route) => ['route' => $route->id], 'lg'),
            Action::delete('transport.manage', __('Past assignments on it are kept.'))
                ->handle(fn (TransportRoute $route) => app(Transport::class)->deleteRoute($route))
                ->success(fn (TransportRoute $route) => __(':name deleted.', ['name' => $route->name])),
        ];
    }

    public function searchPlaceholder(): string
    {
        return __('Search routes…');
    }

    public function emptyTitle(): string
    {
        return __('No routes yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Add each bus route with its stops, pickup times and monthly fare.');
    }
}
