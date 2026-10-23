<?php

/*
 * Permission grid for one role, built from config/access.php: each portal's
 * entry permission, then its modules with their actions.
 *
 * Editors only change permissions they hold themselves (App\Services\Access\
 * AccessGuard); the School Admin role always holds everything and is read-only.
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Role;
use App\Services\Access\AccessGuard;
use App\Support\Access\PermissionCatalog;
use App\Support\Billing\PlanFeatures;
use App\Support\Portal;

use function Livewire\Volt\{computed, layout, mount, state, title, uses};

layout('layouts.app');
title(fn () => __('Permissions: :role', ['role' => $this->roleModel->name]));

uses([InteractsWithUi::class]);

state(['roleId' => null])->locked();

state(['granted' => []]);

$roleModel = computed(fn () => Role::query()->forCurrentSchool()->with('permissions')->findOrFail($this->roleId));

// The built-in School Admin role: always every permission.
$locked = computed(fn () => $this->roleModel->is_system
    && in_array('*', config("access.default_roles.{$this->roleModel->name}.permissions", []), true));

$editable = computed(function () {
    if ($this->locked || ! auth()->user()->can('roles.edit')) {
        return [];
    }

    $guard = app(AccessGuard::class);

    return array_values(array_filter(PermissionCatalog::schoolPermissions(), fn ($name) => $guard->canGrant(auth()->user(), $name)));
});

// Portals with their modules: [portal => ['label', 'icon', 'permission', 'modules' => [module => ['label', 'actions' => [action => permission]]]]]
$groups = computed(function () {
    $groups = [];

    foreach (config('access.portals') as $portal => $definition) {
        $groups[$portal] = [
            'label' => Portal::label($portal),
            'icon' => $definition['icon'],
            'permission' => PermissionCatalog::portalPermission($portal),
            'modules' => [],
        ];
    }

    foreach (config('access.modules') as $module => $definition) {
        $actions = [];

        foreach ($definition['actions'] as $action) {
            $actions[$action] = "{$module}.{$action}";
        }

        $groups[$definition['portal']]['modules'][$module] = ['label' => __($definition['label']), 'actions' => $actions];
    }

    return $groups;
});

mount(function (int $role) {
    $this->authorize('roles.view');

    $this->roleId = Role::query()->forCurrentSchool()->findOrFail($role)->id;
    $this->granted = $this->roleModel->permissions->pluck('name')->values()->all();
});

$toggleModule = function (string $module) {
    $permissions = array_values(array_filter(
        PermissionCatalog::schoolPermissions(),
        fn ($name) => str_starts_with($name, "{$module}.") && in_array($name, $this->editable, true),
    ));

    $this->granted = array_diff($permissions, $this->granted) === []
        ? array_values(array_diff($this->granted, $permissions))
        : array_values(array_unique([...$this->granted, ...$permissions]));
};

$save = function () {
    $this->authorize('roles.edit');
    abort_if($this->locked, 403);

    $role = $this->roleModel;
    $editable = $this->editable;
    $known = PermissionCatalog::schoolPermissions();
    $before = $role->permissions->pluck('name')->all();

    // Changes only count for permissions the editor may grant; the rest stay as they were.
    $wanted = array_intersect(array_map('strval', (array) $this->granted), $known, $editable);
    $untouchable = array_diff($before, $editable);
    $final = array_values(array_unique([...$wanted, ...$untouchable]));

    $role->syncPermissions($final);

    $added = array_values(array_diff($final, $before));
    $removed = array_values(array_diff($before, $final));

    if ($added || $removed) {
        activity('access')->performedOn($role)->causedBy(auth()->user())
            ->withProperties(['added' => $added, 'removed' => $removed])
            ->log('Role permissions changed');
    }

    unset($this->roleModel);
    $this->granted = $final;
    $this->toast(__('Permissions for :role saved.', ['role' => $role->name]));
};

?>

@php
    $role = $this->roleModel;
    $editable = $this->editable;
    $plan = tenant()->plan;
    $grantedSet = array_flip($granted);
    $actionLabels = config('access.action_labels');
@endphp

<div>
    <x-page-header :title="$role->name" :breadcrumbs="[__('School Admin') => route('admin.dashboard'), __('Roles & permissions') => route('admin.roles.index'), $role->name => null]">
        <x-slot:actions>
            @can('roles.edit')
                <button type="button" class="btn btn-light" wire:click="$dispatch('open-modal', { component: 'forms.role', params: { role: {{ $role->id }} } })">
                    <i class="ri-pencil-line me-1"></i>{{ __('Rename') }}
                </button>
            @endcan
            @if ($editable)
                <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <i class="ri-save-line me-1"></i>{{ __('Save permissions') }}
                </button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($role->description)
        <p class="text-muted mt-n3 mb-4">{{ $role->description }}</p>
    @endif

    @if ($this->locked)
        <div class="alert alert-info d-flex gap-2" role="status">
            <i class="ri-lock-line fs-5 lh-1"></i>
            <div>{{ __('The :role role always has every permission, so the school can never be locked out. Create another role for limited access.', ['role' => $role->name]) }}</div>
        </div>
    @elseif (! $editable)
        <div class="alert alert-light border d-flex gap-2" role="status">
            <i class="ri-eye-line fs-5 lh-1"></i>
            <div>{{ __('You can view these permissions but not change them.') }}</div>
        </div>
    @elseif (count($editable) < count(PermissionCatalog::schoolPermissions()))
        <p class="text-muted fs-13"><i class="ri-information-line me-1"></i>{{ __('Greyed-out permissions are ones you don\'t hold yourself, so you can\'t grant or remove them.') }}</p>
    @endif

    <div class="vstack gap-4">
        @foreach ($this->groups as $portal => $group)
            @php
                $portalPermission = $group['permission'];
                $portalAllowed = PlanFeatures::allows($plan, $portalPermission);
            @endphp
            <div class="card mb-0" wire:key="portal-{{ $portal }}">
                <div class="card-header d-flex flex-wrap align-items-center gap-3">
                    <span class="stat-icon bg-primary-subtle text-primary rounded-2 d-inline-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem">
                        <i class="{{ $group['icon'] }}"></i>
                    </span>
                    <div class="flex-grow-1">
                        <h5 class="card-title mb-0">{{ __(':portal portal', ['portal' => $group['label']]) }}</h5>
                        @unless ($portalAllowed)
                            <small class="text-warning"><i class="ri-lock-2-line me-1"></i>{{ __('Not included in your plan') }}</small>
                        @endunless
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input type="checkbox" role="switch" class="form-check-input" id="perm-{{ $portalPermission }}" value="{{ $portalPermission }}"
                               wire:model.live="granted" @disabled(! in_array($portalPermission, $editable, true))>
                        <label class="form-check-label fw-medium" for="perm-{{ $portalPermission }}">{{ __('Can open this portal') }}</label>
                    </div>
                </div>

                @if ($group['modules'])
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <tbody>
                                @foreach ($group['modules'] as $module => $definition)
                                    @php
                                        $moduleAllowed = PlanFeatures::allows($plan, "{$module}.view");
                                        $moduleEditable = array_intersect($definition['actions'], $editable);
                                        $checkedCount = count(array_intersect_key(array_flip($definition['actions']), $grantedSet));
                                    @endphp
                                    <tr wire:key="module-{{ $module }}">
                                        <th scope="row" class="fw-medium" style="width: 16rem">
                                            {{ $definition['label'] }}
                                            @unless ($moduleAllowed)
                                                <i class="ri-lock-2-line text-warning ms-1" title="{{ __('Not included in your plan') }}"></i>
                                                <span class="visually-hidden">{{ __('Not included in your plan') }}</span>
                                            @endunless
                                            <small class="d-block text-muted fw-normal">{{ $checkedCount }} / {{ count($definition['actions']) }}</small>
                                        </th>
                                        <td>
                                            <div class="d-flex flex-wrap gap-2">
                                                @foreach ($definition['actions'] as $action => $permission)
                                                    <input type="checkbox" class="btn-check" id="perm-{{ $permission }}" value="{{ $permission }}" autocomplete="off"
                                                           wire:model.live="granted" @disabled(! in_array($permission, $editable, true))>
                                                    <label class="btn btn-sm btn-outline-primary" for="perm-{{ $permission }}">{{ __($actionLabels[$action] ?? \Illuminate\Support\Str::headline($action)) }}</label>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="text-end" style="width: 1%">
                                            @if ($moduleEditable)
                                                <button type="button" class="btn btn-sm btn-link text-decoration-none" wire:click="toggleModule('{{ $module }}')">
                                                    {{ $checkedCount === count($definition['actions']) ? __('Clear') : __('All') }}
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    @if ($editable)
        <div class="d-flex justify-content-end mt-4">
            <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save"><i class="ri-save-line me-1"></i>{{ __('Save permissions') }}</span>
                <span wire:loading wire:target="save"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Saving…') }}</span>
            </button>
        </div>
    @endif
</div>
