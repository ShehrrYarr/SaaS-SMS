<?php

namespace App\Tables;

use App\Enums\RecordStatus;
use App\Models\Campus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class CampusesTable extends Table
{
    public function permission(): ?string
    {
        return 'campuses.view';
    }

    public function query(): Builder
    {
        return Campus::query()->withCount(['classes', 'classrooms']);
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function columns(): array
    {
        return [
            Column::make('name', __('Campus'))->sortable()->searchable()
                ->format(fn (string $name, Campus $campus) => new HtmlString(
                    '<span class="fw-medium text-body">'.e($name).'</span>'
                    .($campus->is_main ? ' <span class="badge bg-primary-subtle text-primary ms-1">'.e(__('Main')).'</span>' : '')
                    .($campus->code ? '<small class="d-block text-muted">'.e($campus->code).'</small>' : '')
                )),
            Column::make('phone', __('Phone'))->searchable()->hiddenOnMobile(),
            Column::make('email', __('Email'))->searchable()->hiddenOnMobile(),
            Column::make('classes_count', __('Classes'))->sortable(),
            Column::make('classrooms_count', __('Rooms'))->sortable()->hiddenOnMobile(),
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
            Action::make('make-main', __('Make main campus'))
                ->icon('ri-star-line')
                ->color('success')
                ->can('campuses.edit')
                ->visible(fn (Campus $campus) => ! $campus->is_main)
                ->confirm(__('The main campus is the default for new classes, rooms and staff.'), __('Make this the main campus?'))
                ->handle(fn (Campus $campus) => $campus->makeMain())
                ->success(fn (Campus $campus) => __(':name is now the main campus.', ['name' => $campus->name])),

            Action::edit('forms.campus', 'campus', 'campuses.edit'),

            Action::delete('campuses.delete')
                ->handle(function (Campus $campus) {
                    if ($campus->is_main) {
                        throw new ActionFailed(__('The main campus can\'t be deleted. Make another campus main first.'));
                    }

                    if ($campus->classes()->exists()) {
                        throw new ActionFailed(__(':name still has classes. Move or delete them first.', ['name' => $campus->name]));
                    }

                    DB::transaction(fn () => $campus->delete());
                })
                ->success(fn (Campus $campus) => __(':name deleted.', ['name' => $campus->name])),
        ];
    }

    public function searchPlaceholder(): string
    {
        return __('Search campuses…');
    }

    public function emptyTitle(): string
    {
        return __('No campuses yet');
    }
}
