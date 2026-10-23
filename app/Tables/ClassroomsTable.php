<?php

namespace App\Tables;

use App\Enums\ClassroomType;
use App\Models\Campus;
use App\Models\Classroom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ClassroomsTable extends Table
{
    public function permission(): ?string
    {
        return 'classrooms.view';
    }

    public function query(): Builder
    {
        return Classroom::query()->with('campus');
    }

    public function defaultSort(): array
    {
        return ['room_number', 'asc'];
    }

    public function columns(): array
    {
        return [
            Column::make('room_number', __('Room'))->sortable()->searchable()
                ->format(fn (string $number, Classroom $room) => new HtmlString(
                    '<span class="fw-medium text-body">'.e($number).'</span>'
                    .($room->name ? '<small class="d-block text-muted">'.e($room->name).'</small>' : '')
                )),
            Column::make('type', __('Type'))->sortable(),
            Column::make('building', __('Location'))->searchable()->hiddenOnMobile()
                ->format(fn (?string $building, Classroom $room) => collect([$building, $room->floor ? __('Floor :floor', ['floor' => $room->floor]) : null])->filter()->implode(' · ') ?: null),
            Column::make('campus.name', __('Campus'))->hiddenOnMobile(),
            Column::make('capacity', __('Capacity'))->sortable(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('type', __('Type'))->options(ClassroomType::class),
            Filter::make('campus_id', __('Campus'))->options(
                Campus::query()->ordered()->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()
            ),
        ];
    }

    public function actions(): array
    {
        return [
            Action::edit('forms.classroom', 'classroom', 'classrooms.edit'),
            Action::delete('classrooms.delete', __('Sections and timetable slots in this room will no longer have a room.'))
                ->success(fn (Classroom $room) => __('Room :number deleted.', ['number' => $room->room_number])),
        ];
    }

    public function bulkActions(): array
    {
        return [BulkAction::delete('classrooms.delete')];
    }

    public function searchPlaceholder(): string
    {
        return __('Search rooms…');
    }

    public function emptyTitle(): string
    {
        return __('No rooms yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Add classrooms, labs and halls to assign them to sections, timetables and exams.');
    }
}
