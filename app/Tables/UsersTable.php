<?php

namespace App\Tables;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\AccessGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Every account in the school. Accounts are created with their staff,
 * student or parent records; here administrators manage roles, status and
 * passwords.
 */
class UsersTable extends Table
{
    public function permission(): ?string
    {
        return 'users.view';
    }

    public function query(): Builder
    {
        return User::query()->with('roles');
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function columns(): array
    {
        return [
            Column::make('name', __('Name'))->sortable()->searchable()
                ->format(fn (string $name, User $user) => new HtmlString(
                    '<span class="fw-medium text-body">'.e($name).'</span>'
                    .($user->is(auth()->user()) ? ' <span class="badge bg-light text-muted border ms-1">'.e(__('You')).'</span>' : '')
                    .($user->username ? '<small class="d-block text-muted">'.e($user->username).'</small>' : '')
                )),
            Column::make('email', __('Email / phone'))->searchable()->hiddenOnMobile()
                ->format(fn (?string $email, User $user) => collect([$email, $user->phone])->filter()->implode(' · ') ?: null),
            Column::make('user_type', __('Type'))->sortable()->badge(),
            Column::make('roles', __('Roles'))
                ->format(fn ($roles) => $roles->isEmpty()
                    ? new HtmlString('<span class="text-muted">'.e(__('No roles')).'</span>')
                    : new HtmlString($roles->map(fn ($role) => '<span class="badge bg-primary-subtle text-primary me-1">'.e($role->name).'</span>')->implode(''))),
            Column::make('last_login_at', __('Last sign-in'))->sortable()->dateTime()->hiddenOnMobile(),
            Column::make('status', __('Status'))->sortable()->badge(),
        ];
    }

    public function filters(): array
    {
        $types = UserType::options();
        unset($types[UserType::SuperAdmin->value]);

        return [
            Filter::make('user_type', __('Type'))->options($types),
            Filter::make('role', __('Role'))
                ->options(Role::query()->forCurrentSchool()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all())
                ->query(fn (Builder $query, string $roleId) => $query->whereHas('roles', fn (Builder $roles) => $roles->whereKey($roleId))),
            Filter::make('status', __('Status'))->options(UserStatus::class),
        ];
    }

    public function actions(): array
    {
        $manageable = fn (string $permission) => fn (User $editor, User $account) => $editor->can($permission)
            && app(AccessGuard::class)->canManage($editor, $account);

        return [
            Action::make('access', __('Roles & status'))
                ->icon('ri-shield-user-line')
                ->can($manageable('users.edit'))
                ->modal('forms.user-access', fn (User $user) => ['user' => $user->id]),

            Action::make('reset-password', __('Reset password'))
                ->icon('ri-lock-password-line')
                ->color('warning')
                ->can($manageable('users.reset-password'))
                ->modal('forms.reset-user-password', fn (User $user) => ['user' => $user->id]),
        ];
    }

    /**
     * People are looked up by whatever they sign in with.
     */
    protected function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->where(fn (Builder $query) => $query
            ->where('name', 'like', $term)
            ->orWhere('username', 'like', $term)
            ->orWhere('email', 'like', $term)
            ->orWhere('phone', 'like', $term));
    }

    public function searchPlaceholder(): string
    {
        return __('Search by name, username, email or phone…');
    }

    public function emptyTitle(): string
    {
        return __('No accounts');
    }
}
