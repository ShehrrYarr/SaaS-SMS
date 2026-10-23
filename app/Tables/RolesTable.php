<?php

namespace App\Tables;

use App\Models\Role;
use App\Support\Access\PermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * The school's roles. Role isn't tenant-scoped (see App\Models\Role), so the
 * query is limited to the active school explicitly.
 */
class RolesTable extends Table
{
    public function permission(): ?string
    {
        return 'roles.view';
    }

    public function query(): Builder
    {
        return Role::query()->forCurrentSchool()->withCount(['users', 'permissions']);
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function columns(): array
    {
        $total = count(PermissionCatalog::schoolPermissions());

        return [
            Column::make('name', __('Role'))->sortable()->searchable()
                ->format(fn (string $name, Role $role) => new HtmlString(
                    '<a href="'.e(route('admin.roles.permissions', $role->id)).'" wire:navigate class="fw-medium">'.e($name).'</a>'
                    .($role->is_system ? ' <span class="badge bg-light text-muted border ms-1">'.e(__('Built-in')).'</span>' : '')
                    .($role->description ? '<small class="d-block text-muted text-truncate" style="max-width: 24rem">'.e($role->description).'</small>' : '')
                )),
            Column::make('users_count', __('Users'))->sortable(),
            Column::make('permissions_count', __('Permissions'))->sortable()
                ->format(fn (int $count) => "{$count} / {$total}"),
        ];
    }

    public function actions(): array
    {
        return [
            Action::make('permissions', __('Permissions'))
                ->icon('ri-shield-keyhole-line')
                ->url(fn (Role $role) => route('admin.roles.permissions', $role->id)),

            Action::edit('forms.role', 'role', 'roles.edit'),

            Action::delete('roles.delete')
                ->visible(fn (Role $role) => ! $role->is_system)
                ->handle(function (Role $role) {
                    if ($role->users()->exists()) {
                        throw new ActionFailed(__(':name is held by some users. Take it away from them first.', ['name' => $role->name]));
                    }

                    $role->delete();
                })
                ->success(fn (Role $role) => __(':name deleted.', ['name' => $role->name])),
        ];
    }

    public function searchPlaceholder(): string
    {
        return __('Search roles…');
    }

    public function emptyTitle(): string
    {
        return __('No roles yet');
    }
}
