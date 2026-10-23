<?php

/*
 * An account's roles and status.
 *     $dispatch('open-modal', { component: 'forms.user-access', params: { user: 12 } })
 *
 * Only roles the editor could hold in full can be given or taken away, and
 * nobody manages their own account or one with more access than they have.
 */

use App\Enums\UserStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\AccessGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, protect, rules, state, uses};

uses([InteractsWithUi::class]);

state(['user' => null, 'name' => ''])->locked();

state(['roles' => [], 'status' => UserStatus::Active->value]);

$account = protect(fn () => User::query()->with('roles')->findOrFail($this->user));

$allRoles = computed(fn () => Role::query()->forCurrentSchool()->with('permissions')->orderBy('name')->get());

$assignable = computed(fn () => $this->allRoles
    ->filter(fn (Role $role) => app(AccessGuard::class)->canAssign(auth()->user(), $role))
    ->pluck('id')
    ->all());

mount(function (int $user) {
    $this->authorize('users.edit');

    $this->user = $user;
    $account = $this->account();
    abort_unless(app(AccessGuard::class)->canManage(auth()->user(), $account), 403);

    $this->name = $account->name;
    $this->roles = $account->roles->pluck('id')->map(fn ($id) => (string) $id)->all();
    $this->status = $account->status->value;
});

rules([
    'roles' => ['array'],
    'roles.*' => ['integer'],
    'status' => ['required', Rule::enum(UserStatus::class)],
]);

$save = function () {
    $this->authorize('users.edit');
    $data = $this->validate();

    $account = $this->account();
    abort_unless(app(AccessGuard::class)->canManage(auth()->user(), $account), 403);

    $schoolRoles = $this->allRoles->pluck('id')->all();
    $before = $account->roles->pluck('id')->all();
    $wanted = array_intersect(array_map('intval', $data['roles']), $schoolRoles, $this->assignable);
    $untouchable = array_diff($before, $this->assignable);
    $final = array_values(array_unique([...$wanted, ...$untouchable]));

    DB::transaction(function () use ($account, $final, $before, $data) {
        $account->syncRoles($final);
        $account->update(['status' => $data['status']]);

        if (array_diff($final, $before) || array_diff($before, $final) || $account->wasChanged('status')) {
            activity('access')->performedOn($account)->causedBy(auth()->user())
                ->withProperties([
                    'roles' => $this->allRoles->whereIn('id', $final)->pluck('name')->values()->all(),
                    'status' => $data['status'],
                ])
                ->log('Account access changed');
        }
    });

    $this->closeModal();
    $this->refreshTables();
    $this->toast(__('Access for :name saved.', ['name' => $this->name]));
};

?>

<x-modal.form :title="__('Roles & status')" :subtitle="$name">
    <fieldset class="mb-4">
        <legend class="form-label fs-14">{{ __('Roles') }}</legend>
        <div class="vstack gap-2">
            @foreach ($this->allRoles as $role)
                @php($allowed = in_array($role->id, $this->assignable, true))
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="role-{{ $role->id }}" value="{{ $role->id }}" wire:model="roles" @disabled(! $allowed)>
                    <label class="form-check-label" for="role-{{ $role->id }}">
                        <span class="fw-medium">{{ $role->name }}</span>
                        @if ($role->description)
                            <small class="d-block text-muted">{{ $role->description }}</small>
                        @endif
                        @unless ($allowed)
                            <small class="d-block text-warning">{{ __('Has permissions you don\'t hold, so you can\'t change it.') }}</small>
                        @endunless
                    </label>
                </div>
            @endforeach
        </div>
    </fieldset>

    <x-form.select name="status" :label="__('Status')" :options="UserStatus::options()" :placeholder="false" required wrapper="mb-0"
                   :hint="__('Inactive and suspended accounts are signed out and can\'t sign in.')" />
</x-modal.form>
