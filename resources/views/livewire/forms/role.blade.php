<?php

/*
 * Create a role, or rename / describe one. Permissions are edited on the
 * role's permissions page.
 *     $dispatch('open-modal', { component: 'forms.role' })
 *     $dispatch('open-modal', { component: 'forms.role', params: { role: 7 } })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Role;
use App\Services\Access\AccessGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['role' => null, 'isSystem' => false])->locked();

state(['name' => '', 'description' => '', 'copy_from' => '']);

$roles = computed(fn () => Role::query()->forCurrentSchool()->orderBy('name')->pluck('name', 'id')->all());

mount(function (?int $role = null) {
    if (! $role) {
        $this->authorize('roles.create');

        return;
    }

    $this->authorize('roles.edit');
    $record = Role::query()->forCurrentSchool()->findOrFail($role);

    $this->role = $record->id;
    $this->isSystem = $record->is_system;
    $this->name = $record->name;
    $this->description = (string) $record->description;
});

rules(fn () => [
    'name' => [
        'required', 'string', 'max:60',
        Rule::unique('roles', 'name')->where('school_id', tenant()->id)->where('guard_name', 'web')->ignore($this->role),
    ],
    'description' => ['nullable', 'string', 'max:255'],
    'copy_from' => ['nullable', Rule::exists('roles', 'id')->where('school_id', tenant()->id)],
])->attributes(['copy_from' => __('role to copy')]);

$save = function (AccessGuard $guard) {
    $this->authorize($this->role ? 'roles.edit' : 'roles.create');
    $data = $this->validate();

    $created = DB::transaction(function () use ($data, $guard) {
        if ($this->role) {
            $role = Role::query()->forCurrentSchool()->findOrFail($this->role);

            // Built-in roles keep their names: code and config refer to them.
            $role->update([
                'name' => $this->isSystem ? $role->name : $data['name'],
                'description' => $data['description'] ?: null,
            ]);

            return null;
        }

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web', 'description' => $data['description'] ?: null]);

        if ($data['copy_from']) {
            // Copy only what the editor could grant by hand.
            $source = Role::query()->forCurrentSchool()->findOrFail($data['copy_from']);
            $role->syncPermissions($source->permissions->pluck('name')->filter(fn ($name) => $guard->canGrant(auth()->user(), $name))->all());
        }

        activity('access')->performedOn($role)->causedBy(auth()->user())->log('Role created');

        return $role;
    });

    $this->closeModal();
    $this->refreshTables();

    if ($created) {
        $this->flashToast(__(':name created. Choose what it can do.', ['name' => $created->name]));
        $this->redirectRoute('admin.roles.permissions', $created->id, navigate: true);

        return;
    }

    $this->toast(__('Role updated.'));
};

?>

<x-modal.form :title="$role ? __('Edit role') : __('New role')"
              :subtitle="$role ? null : __('Next you\'ll choose which screens and actions it opens.')">
    <x-form.input name="name" :label="__('Name')" required maxlength="60" :disabled="$isSystem" :placeholder="__('e.g. Exam Coordinator')"
                  :hint="$isSystem ? __('Built-in roles can\'t be renamed.') : null" />

    <x-form.textarea name="description" :label="__('Description')" rows="2" maxlength="255" :wrapper="$role ? 'mb-0' : 'mb-4'" />

    @unless ($role)
        <x-form.select name="copy_from" :label="__('Start from')" :options="$this->roles" :placeholder="__('No permissions')" wrapper="mb-0"
                       :hint="__('Copies another role\'s permissions as a starting point.')" />
    @endunless
</x-modal.form>
