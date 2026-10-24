<?php

namespace App\Tables;

use App\Enums\AllocationStatus;
use App\Models\HostelBuilding;
use App\Models\StudentHostel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Hostel residents. Params: ['student' => id] for one student's history.
 * Opens on current residents.
 */
class StudentHostelsTable extends Table
{
    public function permission(): ?string
    {
        return 'hostel.view';
    }

    public function query(): Builder
    {
        return StudentHostel::query()
            ->with([
                'student' => fn ($query) => $query->withTrashed()->with(['user', 'schoolClass', 'section']),
                'room' => fn ($query) => $query->withTrashed()->with(['building' => fn ($query) => $query->withTrashed()]),
            ])
            ->when($this->params['student'] ?? null, fn (Builder $query, $student) => $query->where('student_profile_id', (int) $student));
    }

    public function defaultSort(): array
    {
        return ['check_in', 'desc'];
    }

    public function defaultFilters(): array
    {
        return isset($this->params['student']) ? [] : ['status' => AllocationStatus::Active->value];
    }

    public function columns(): array
    {
        $format = tenant()?->setting('date_format') ?? 'd M Y';

        return array_values(array_filter([
            isset($this->params['student']) ? null : Column::make('student', __('Student'))->virtual()
                ->format(fn ($value, StudentHostel $row) => Cells::person(
                    $row->student?->user,
                    collect([$row->student?->admission_number, $row->student?->placementLabel()])->filter()->implode(' · '),
                    $row->student && auth()->user()?->can('students.view') ? route('admin.students.show', $row->student->id) : null,
                )),
            Column::make('hostel_room_id', __('Room'))
                ->format(fn ($value, StudentHostel $row) => new HtmlString(
                    '<span class="d-block">'.e($row->room?->label(false)).($row->bed_number ? ' · '.e(__('Bed :bed', ['bed' => $row->bed_number])) : '').'</span>'
                    .'<small class="text-muted">'.e($row->room?->building?->name).'</small>',
                )),
            Column::make('check_in', __('Stay'))->sortable()
                ->format(fn ($value, StudentHostel $row) => new HtmlString(
                    '<span class="text-nowrap">'.e(__('from :date', ['date' => $row->check_in->format($format)])).'</span>'
                    .($row->check_out ? '<small class="d-block text-muted text-nowrap">'.e(__('until :date', ['date' => $row->check_out->format($format)])).'</small>' : ''),
                )),
            Column::make('status', __('Status'))->sortable()->badge(),
        ]));
    }

    public function filters(): array
    {
        return array_values(array_filter([
            Filter::make('status', __('Status'))->options(AllocationStatus::options()),
            isset($this->params['student']) ? null : Filter::make('building', __('Building'))
                ->options(HostelBuilding::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all())
                ->query(fn (Builder $query, string $value) => $query->whereHas('room', fn (Builder $room) => $room->withTrashed()->where('hostel_building_id', (int) $value))),
        ]));
    }

    public function actions(): array
    {
        return [
            Action::make('move', __('Move room'))
                ->icon('ri-arrow-left-right-line')
                ->modal('forms.student-hostel', fn (StudentHostel $row) => ['stay' => $row->id])
                ->can('hostel.manage')
                ->visible(fn (StudentHostel $row) => $row->isActive()),
            Action::make('checkout', __('Check out'))
                ->icon('ri-logout-box-r-line')
                ->color('danger')
                ->modal('forms.end-service', fn (StudentHostel $row) => ['kind' => 'hostel', 'id' => $row->id])
                ->can('hostel.manage')
                ->visible(fn (StudentHostel $row) => $row->isActive()),
        ];
    }

    protected function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->where(fn (Builder $query) => $query
            ->whereHas('student', fn (Builder $student) => $student
                ->where('admission_number', 'like', $term)
                ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $term)))
            ->orWhereHas('room', fn (Builder $room) => $room->where('room_number', 'like', $term)));
    }

    public function isSearchable(): bool
    {
        return true;
    }

    public function searchPlaceholder(): string
    {
        return __('Search by student or room…');
    }

    public function emptyTitle(): string
    {
        return isset($this->params['student']) ? __('Not staying in the hostel') : __('Nobody is staying in the hostel');
    }
}
