<?php

namespace App\Tables;

use App\Enums\RecordStatus;
use App\Models\TransportVehicle;
use App\Services\Transport\Transport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * School buses and vans with their drivers.
 */
class TransportVehiclesTable extends Table
{
    public function permission(): ?string
    {
        return 'transport.view';
    }

    public function query(): Builder
    {
        return TransportVehicle::query()->withCount('routes');
    }

    public function defaultSort(): array
    {
        return ['vehicle_number', 'asc'];
    }

    public function columns(): array
    {
        return [
            Column::make('vehicle_number', __('Vehicle'))->sortable()->searchable()
                ->format(fn (string $number, TransportVehicle $vehicle) => new HtmlString(
                    '<span class="fw-medium text-body d-block">'.e($number).'</span>'
                    .($vehicle->model ? '<small class="text-muted">'.e($vehicle->model).'</small>' : ''),
                )),
            Column::make('driver_name', __('Driver'))->sortable()->searchable()
                ->format(fn (?string $name, TransportVehicle $vehicle) => $name ? new HtmlString(
                    '<span class="d-block">'.e($name).'</span>'
                    .($vehicle->driver_phone ? '<small class="text-muted"><i class="ri-phone-line me-1"></i>'.e($vehicle->driver_phone).'</small>' : ''),
                ) : null),
            Column::make('driver_license', __('Licence'))->hiddenOnMobile(),
            Column::make('capacity', __('Seats'))->sortable(),
            Column::make('routes_count', __('Routes'))->sortable(),
            Column::make('status', __('Status'))->sortable()->badge(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('status', __('Status'))->options(RecordStatus::class),
        ];
    }

    public function actions(): array
    {
        return [
            Action::edit('forms.transport-vehicle', 'vehicle', 'transport.manage'),
            Action::delete('transport.manage', __('Routes it runs will have no vehicle until you give them another.'))
                ->handle(fn (TransportVehicle $vehicle) => app(Transport::class)->deleteVehicle($vehicle))
                ->success(fn (TransportVehicle $vehicle) => __(':number removed.', ['number' => $vehicle->vehicle_number])),
        ];
    }

    public function searchPlaceholder(): string
    {
        return __('Search by number or driver…');
    }

    public function emptyTitle(): string
    {
        return __('No vehicles yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Add the school\'s buses and vans with their drivers, then give each route a vehicle.');
    }
}
