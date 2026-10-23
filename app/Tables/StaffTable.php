<?php

namespace App\Tables;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Department;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Access\AccessGuard;
use App\Services\Staff\StaffRecords;
use App\Support\Portal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * The staff directory, shared by the School Admin and HR portals (pass
 * ['portal' => 'hr'] so links stay inside HR).
 */
class StaffTable extends Table
{
    public function permission(): ?string
    {
        return 'staff.view';
    }

    public function query(): Builder
    {
        return StaffProfile::query()->with(['user.roles', 'department']);
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    private function portal(): string
    {
        return in_array($this->params['portal'] ?? null, ['admin', 'hr'], true) ? $this->params['portal'] : 'admin';
    }

    public function columns(): array
    {
        return [
            Column::make('name', __('Name'))->virtual()->searchable()
                ->sortable(fn (Builder $query, string $direction) => $query->orderBy(
                    User::query()->select('name')->whereColumn('users.id', 'staff_profiles.user_id')->limit(1),
                    $direction,
                ))
                ->format(fn ($value, StaffProfile $staff) => Cells::person(
                    $staff->user,
                    $staff->employee_id,
                    Portal::route($this->portal(), 'staff.show', $staff->id),
                )),
            Column::make('designation', __('Designation'))->sortable()
                ->format(fn (?string $designation, StaffProfile $staff) => new HtmlString(
                    '<span class="d-block text-body">'.e($designation ?: '—').'</span>'
                    .($staff->department ? '<small class="d-block text-muted">'.e($staff->department->name).'</small>' : ''),
                )),
            Column::make('roles', __('Roles'))->virtual()->hiddenOnMobile()
                ->format(fn ($value, StaffProfile $staff) => Cells::badges($staff->user?->roles->pluck('name') ?? [], 'primary', __('No roles'))),
            Column::make('contact', __('Phone / email'))->virtual()->hiddenOnMobile()
                ->format(fn ($value, StaffProfile $staff) => collect([$staff->user?->phone, $staff->user?->email])->filter()->implode(' · ') ?: null),
            Column::make('joining_date', __('Joined'))->sortable()->date()->hiddenOnMobile(),
            Column::make('status', __('Account'))->virtual()
                ->format(fn ($value, StaffProfile $staff) => $staff->user
                    ? new HtmlString(sprintf('<span class="badge bg-%1$s-subtle text-%1$s">%2$s</span>', $staff->user->status->color(), e($staff->user->status->label())))
                    : null),
        ];
    }

    public function filters(): array
    {
        $types = collect(UserType::cases())->filter(fn (UserType $type) => $type->isStaff())
            ->mapWithKeys(fn (UserType $type) => [$type->value => $type->label()])->all();

        return [
            Filter::make('department_id', __('Department'))
                ->options(Department::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()),
            Filter::make('type', __('Type'))
                ->options($types)
                ->query(fn (Builder $query, string $type) => $query->whereHas('user', fn (Builder $user) => $user->where('user_type', $type))),
            Filter::make('status', __('Account'))
                ->options(UserStatus::class)
                ->query(fn (Builder $query, string $status) => $query->whereHas('user', fn (Builder $user) => $user->where('status', $status))),
        ];
    }

    public function actions(): array
    {
        $manageable = fn (string $permission) => fn (User $editor, StaffProfile $staff) => $editor->can($permission)
            && $staff->user && app(AccessGuard::class)->canManage($editor, $staff->user);

        return [
            Action::make('view', __('View profile'))
                ->icon('ri-eye-line')
                ->url(fn (StaffProfile $staff) => Portal::route($this->portal(), 'staff.show', $staff->id)),

            Action::make('edit', __('Edit'))
                ->icon('ri-pencil-line')
                ->can($manageable('staff.edit'))
                ->url(fn (StaffProfile $staff) => Portal::route($this->portal(), 'staff.edit', $staff->id)),

            Action::make('reset-password', __('Reset password'))
                ->icon('ri-lock-password-line')
                ->color('warning')
                ->can($manageable('users.reset-password'))
                ->modal('forms.reset-user-password', fn (StaffProfile $staff) => ['user' => $staff->user_id]),

            Action::make('delete', __('Delete'))
                ->icon('ri-delete-bin-line')
                ->color('danger')
                ->can($manageable('staff.delete'))
                ->confirm(__('Their account is removed and they stop being class teacher or subject teacher anywhere. Attendance and payroll records are kept.'), __('Delete this staff member?'))
                ->handle(fn (StaffProfile $staff) => app(StaffRecords::class)->delete($staff, auth()->user()))
                ->success(__('Staff member deleted.')),
        ];
    }

    protected function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->where(fn (Builder $query) => $query
            ->where('employee_id', 'like', $term)
            ->orWhere('designation', 'like', $term)
            ->orWhereHas('user', fn (Builder $user) => $user
                ->where('name', 'like', $term)
                ->orWhere('username', 'like', $term)
                ->orWhere('phone', 'like', $term)
                ->orWhere('email', 'like', $term)));
    }

    public function searchPlaceholder(): string
    {
        return __('Search by name, employee ID, designation or phone…');
    }

    public function emptyTitle(): string
    {
        return __('No staff yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Add staff one by one, or import them from a spreadsheet.');
    }
}
