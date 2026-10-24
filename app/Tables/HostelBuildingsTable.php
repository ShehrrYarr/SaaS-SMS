<?php

namespace App\Tables;

use App\Enums\AllocationStatus;
use App\Enums\HostelType;
use App\Models\HostelBuilding;
use App\Models\StudentHostel;
use App\Services\Hostel\Hostels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Hostel buildings with their warden and how full they are.
 */
class HostelBuildingsTable extends Table
{
    public function permission(): ?string
    {
        return 'hostel.view';
    }

    public function query(): Builder
    {
        return HostelBuilding::query()
            ->with('warden.user')
            ->withCount('rooms')
            ->withSum('rooms as beds', 'capacity')
            ->addSelect(['residents' => StudentHostel::query()
                ->selectRaw('count(*)')
                ->join('hostel_rooms', 'hostel_rooms.id', '=', 'student_hostels.hostel_room_id')
                ->whereColumn('hostel_rooms.hostel_building_id', 'hostel_buildings.id')
                ->whereNull('hostel_rooms.deleted_at')
                ->where('student_hostels.status', AllocationStatus::Active)]);
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function columns(): array
    {
        return [
            Column::make('name', __('Building'))->sortable()->searchable()
                ->format(fn (string $name, HostelBuilding $building) => new HtmlString(
                    '<span class="fw-medium text-body d-block">'.e($name).'</span>'
                    .($building->address ? '<small class="text-muted">'.e(str($building->address)->limit(60)).'</small>' : ''),
                )),
            Column::make('type', __('For'))->sortable(),
            Column::make('warden', __('Warden'))->virtual()->hiddenOnMobile()
                ->format(fn ($value, HostelBuilding $building) => Cells::contact($building->warden?->user)),
            Column::make('rooms_count', __('Rooms'))->sortable(),
            Column::make('residents', __('Beds taken'))->sortable()
                ->format(function ($residents, HostelBuilding $building) {
                    $beds = (int) $building->beds;
                    $residents = (int) $residents;

                    if ($beds === 0) {
                        return null;
                    }

                    $color = $residents >= $beds ? 'danger' : ($residents >= $beds * 0.8 ? 'warning' : 'success');

                    return new HtmlString('<span class="badge bg-'.$color.'-subtle text-'.$color.'">'.e(__(':taken of :beds', ['taken' => $residents, 'beds' => $beds])).'</span>');
                }),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('type', __('For'))->options(HostelType::class),
        ];
    }

    public function actions(): array
    {
        return [
            Action::edit('forms.hostel-building', 'building', 'hostel.manage'),
            Action::delete('hostel.manage', __('Its rooms are removed too. Past stays are kept.'))
                ->handle(fn (HostelBuilding $building) => app(Hostels::class)->deleteBuilding($building))
                ->success(fn (HostelBuilding $building) => __(':name deleted.', ['name' => $building->name])),
        ];
    }

    public function emptyTitle(): string
    {
        return __('No hostel buildings yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Add each hostel block, then its rooms and beds.');
    }
}
