<?php

namespace App\Tables;

use App\Enums\RecordStatus;
use App\Models\HostelBuilding;
use App\Models\HostelRoom;
use App\Services\Hostel\Hostels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Hostel rooms with their beds, residents and monthly fee.
 */
class HostelRoomsTable extends Table
{
    public function permission(): ?string
    {
        return 'hostel.view';
    }

    public function query(): Builder
    {
        return HostelRoom::query()
            ->with(['building', 'activeAllocations.student.user'])
            ->withCount('activeAllocations');
    }

    public function defaultSort(): array
    {
        return ['room_number', 'asc'];
    }

    public function columns(): array
    {
        return [
            // Building by building, then room number.
            Column::make('room_number', __('Room'))->searchable()
                ->sortable(fn (Builder $query, string $direction) => $query
                    ->orderBy(HostelBuilding::query()->withTrashed()->select('name')->whereColumn('hostel_buildings.id', 'hostel_rooms.hostel_building_id'), $direction)
                    ->orderBy('room_number', $direction))
                ->format(fn (string $number, HostelRoom $room) => new HtmlString(
                    '<span class="fw-medium text-body d-block">'.e(__('Room :number', ['number' => $number])).'</span>'
                    .'<small class="text-muted">'.e(collect([$room->building?->name, $room->floor ? __('Floor: :floor', ['floor' => $room->floor]) : null, $room->room_type])->filter()->implode(' · ')).'</small>',
                )),
            Column::make('active_allocations_count', __('Beds taken'))->sortable()
                ->format(function (int $taken, HostelRoom $room) {
                    $color = $taken >= $room->capacity ? 'danger' : ($taken > 0 ? 'warning' : 'success');
                    $names = $room->activeAllocations->map(fn ($stay) => $stay->student?->user?->name)->filter();

                    return new HtmlString('<span class="badge bg-'.$color.'-subtle text-'.$color.'">'.e(__(':taken of :beds', ['taken' => $taken, 'beds' => $room->capacity])).'</span>'
                        .($names->isNotEmpty() ? '<small class="d-block text-muted text-truncate" style="max-width: 16rem">'.e($names->implode(', ')).'</small>' : ''));
                }),
            Column::make('fee', __('Monthly fee'))->sortable()->money(),
            Column::make('status', __('Status'))->sortable()->badge(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('hostel_building_id', __('Building'))
                ->options(HostelBuilding::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()),
            Filter::make('beds', __('Beds'))
                ->options(['free' => __('Has a free bed'), 'full' => __('Full')])
                ->query(fn (Builder $query, string $value) => $value === 'free'
                    ? $query->where('status', RecordStatus::Active)->whereRaw('capacity > (select count(*) from student_hostels where student_hostels.hostel_room_id = hostel_rooms.id and student_hostels.status = ?)', ['active'])
                    : $query->whereRaw('capacity <= (select count(*) from student_hostels where student_hostels.hostel_room_id = hostel_rooms.id and student_hostels.status = ?)', ['active'])),
            Filter::make('status', __('Status'))->options(RecordStatus::class),
        ];
    }

    public function actions(): array
    {
        return [
            Action::make('allocate', __('Allocate a bed'))
                ->icon('ri-user-add-line')
                ->color('success')
                ->modal('forms.student-hostel', fn (HostelRoom $room) => ['room' => $room->id])
                ->can('hostel.manage')
                ->visible(fn (HostelRoom $room) => $room->isActive() && $room->active_allocations_count < $room->capacity),
            Action::edit('forms.hostel-room', 'room', 'hostel.manage'),
            Action::delete('hostel.manage', __('Past stays in it are kept.'))
                ->handle(fn (HostelRoom $room) => app(Hostels::class)->deleteRoom($room))
                ->success(fn (HostelRoom $room) => __('Room :number deleted.', ['number' => $room->room_number])),
        ];
    }

    public function searchPlaceholder(): string
    {
        return __('Search by room number…');
    }

    public function emptyTitle(): string
    {
        return __('No rooms yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Add rooms to a building with the number of beds and the monthly fee.');
    }
}
