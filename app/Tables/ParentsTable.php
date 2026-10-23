<?php

namespace App\Tables;

use App\Enums\UserStatus;
use App\Models\ParentProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Access\AccessGuard;
use App\Services\Students\ParentRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Parents and guardians with the children they are linked to.
 */
class ParentsTable extends Table
{
    public function permission(): ?string
    {
        return 'parents.view';
    }

    public function query(): Builder
    {
        return ParentProfile::query()->with(['user', 'children.user', 'children.schoolClass', 'children.section']);
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function columns(): array
    {
        return [
            Column::make('name', __('Parent'))->virtual()->searchable()
                ->sortable(fn (Builder $query, string $direction) => $query->orderBy(
                    User::query()->select('name')->whereColumn('users.id', 'parent_profiles.user_id')->limit(1),
                    $direction,
                ))
                ->format(fn ($value, ParentProfile $parent) => Cells::person($parent->user, $parent->user?->username)),
            Column::make('contact', __('Phone / email'))->virtual()->hiddenOnMobile()
                ->format(fn ($value, ParentProfile $parent) => collect([$parent->user?->phone, $parent->user?->email])->filter()->implode(' · ') ?: null),
            Column::make('children', __('Children'))->virtual()
                ->format(function ($value, ParentProfile $parent) {
                    if ($parent->children->isEmpty()) {
                        return new HtmlString('<span class="text-muted">'.e(__('None linked')).'</span>');
                    }

                    return new HtmlString($parent->children->map(fn (StudentProfile $child) => '<a href="'.e(route('admin.students.show', $child->id)).'" wire:navigate class="badge bg-primary-subtle text-primary me-1 mb-1 text-decoration-none">'
                        .e($child->user?->name).($child->schoolClass ? ' <span class="opacity-75">· '.e($child->placementLabel()).'</span>' : '').'</a>')->implode(''));
                }),
            Column::make('occupation', __('Occupation'))->hiddenOnMobile(),
            Column::make('status', __('Account'))->virtual()
                ->format(fn ($value, ParentProfile $parent) => $parent->user
                    ? new HtmlString(sprintf('<span class="badge bg-%1$s-subtle text-%1$s">%2$s</span>', $parent->user->status->color(), e($parent->user->status->label())))
                    : null),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('linked', __('Children'))
                ->options(['yes' => __('Linked'), 'no' => __('None linked')])
                ->query(fn (Builder $query, string $value) => $value === 'yes' ? $query->has('children') : $query->doesntHave('children')),
            Filter::make('status', __('Account'))
                ->options(UserStatus::class)
                ->query(fn (Builder $query, string $value) => $query->whereHas('user', fn (Builder $user) => $user->where('status', $value))),
        ];
    }

    public function actions(): array
    {
        return [
            Action::edit('forms.parent', 'parent', 'parents.edit')->modal('forms.parent', fn (ParentProfile $parent) => ['parent' => $parent->id], 'lg'),

            Action::make('reset-password', __('Reset password'))
                ->icon('ri-lock-password-line')
                ->color('warning')
                ->can(fn (User $editor, ParentProfile $parent) => $editor->can('users.reset-password')
                    && $parent->user && app(AccessGuard::class)->canManage($editor, $parent->user))
                ->modal('forms.reset-user-password', fn (ParentProfile $parent) => ['user' => $parent->user_id]),

            Action::make('delete', __('Delete'))
                ->icon('ri-delete-bin-line')
                ->color('danger')
                ->can(fn (User $editor, ParentProfile $parent) => $editor->can('parents.delete')
                    && (! $parent->user || $parent->user->is($editor) === false))
                ->confirm(__('They are unlinked from their children. A parent-only account is removed; a staff member keeps their staff account.'), __('Delete this parent?'))
                ->handle(fn (ParentProfile $parent) => app(ParentRecords::class)->delete($parent, auth()->user()))
                ->success(__('Parent deleted.')),
        ];
    }

    protected function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->where(fn (Builder $query) => $query
            ->whereHas('user', fn (Builder $user) => $user
                ->where('name', 'like', $term)
                ->orWhere('username', 'like', $term)
                ->orWhere('phone', 'like', $term)
                ->orWhere('email', 'like', $term))
            ->orWhereHas('children.user', fn (Builder $user) => $user->where('name', 'like', $term)));
    }

    public function searchPlaceholder(): string
    {
        return __('Search by name, phone, email or child…');
    }

    public function emptyTitle(): string
    {
        return __('No parents yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Parents are added when students are admitted, or here.');
    }
}
