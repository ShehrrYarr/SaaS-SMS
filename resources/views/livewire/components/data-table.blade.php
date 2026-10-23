<?php

/*
 * Shared data table. Usage:
 *     <livewire:components.data-table :table="App\Tables\AcademicYearsTable::class" />
 *
 * The table class (App\Tables\Table) describes query, columns, filters and
 * actions; this component owns search, sorting, filters, paging and
 * selection. Reloads when anything dispatches "refresh-tables".
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Tables\ActionFailed;
use App\Tables\Table;
use Illuminate\Support\Facades\DB;

use function Livewire\Volt\{computed, hydrate, mount, on, protect, state, updated, uses, usesPagination};

uses([InteractsWithUi::class]);
usesPagination(theme: 'bootstrap');

state(['table' => null, 'params' => []])->locked();

state([
    'search' => '',
    'sortBy' => null,
    'sortDirection' => 'asc',
    'perPage' => 10,
    'filters' => [],
    'selected' => [],
]);

mount(function (string $table, array $params = []) {
    abort_unless(is_subclass_of($table, Table::class), 404);

    $this->table = $table;
    $this->params = $params;

    $this->definition->authorize();

    [$this->sortBy, $this->sortDirection] = $this->definition->defaultSort();
    $this->perPage = $this->definition->perPage;
});

// Permissions can change mid-session: check again on every request.
hydrate(fn () => $this->definition->authorize());

$definition = computed(fn (): Table => app($this->table)->withParams($this->params));

$rows = computed(fn () => $this->definition->rows(
    (string) $this->search,
    $this->sortBy,
    $this->sortDirection,
    (array) $this->filters,
    (int) $this->perPage,
));

$resetView = protect(function () {
    $this->selected = [];
    $this->resetPage();
});

updated([
    'search' => fn () => $this->resetView(),
    'filters' => fn () => $this->resetView(),
    'perPage' => fn () => $this->resetView(),
]);

on(['refresh-tables' => fn () => null]);

$sort = function (string $column) {
    if (! $this->definition->column($column)?->isSortable()) {
        return;
    }

    $this->sortDirection = $this->sortBy === $column && $this->sortDirection === 'asc' ? 'desc' : 'asc';
    $this->sortBy = $column;
    $this->resetPage();
};

$togglePage = function (array $keys) {
    $keys = array_map('strval', $keys);
    $allSelected = array_diff($keys, $this->selected) === [];

    $this->selected = $allSelected
        ? array_values(array_diff($this->selected, $keys))
        : array_values(array_unique([...$this->selected, ...$keys]));
};

$clearFilters = function () {
    $this->search = '';
    $this->filters = [];
    $this->resetView();
};

$runAction = function (string $name, int|string $key) {
    $action = $this->definition->action($name);
    abort_if($action === null || $action->isModal(), 404);

    // Resolved through the table's own (tenant-scoped) query.
    $row = $this->definition->findRow($key);
    abort_unless($action->isAllowed(auth()->user(), $row), 403);

    try {
        $message = DB::transaction(fn () => $action->run($row));
    } catch (ActionFailed $e) {
        $this->toast($e->getMessage(), 'error');

        return;
    }

    $this->selected = array_values(array_diff($this->selected, [(string) $key]));

    if ($message) {
        $this->toast($message);
    }
};

$runBulkAction = function (string $name) {
    $action = $this->definition->bulkAction($name);
    abort_if($action === null, 404);
    abort_unless($action->isAllowed(auth()->user()), 403);

    $rows = $this->definition->query()->whereKey(array_slice($this->selected, 0, 1000))->get();

    if ($rows->isEmpty()) {
        $this->toast(__('Select at least one row first.'), 'warning');

        return;
    }

    try {
        $message = DB::transaction(fn () => $action->run($rows));
    } catch (ActionFailed $e) {
        $this->toast($e->getMessage(), 'error');

        return;
    }

    $this->selected = [];

    if ($message) {
        $this->toast($message);
    }
};

?>

@php
    $definition = $this->definition;
    $rows = $this->rows;
    $user = auth()->user();
    $columns = $definition->columns();
    $filters = $definition->filters();
    $actions = $definition->actions();
    $bulkActions = collect($definition->bulkActions())->filter->isAllowed($user)->values();
    $selectable = $bulkActions->isNotEmpty();
    $pageKeys = $rows->getCollection()->map(fn ($row) => (string) $row->getKey())->all();
    $pageSelected = $pageKeys !== [] && array_diff($pageKeys, $selected) === [];
    $filtering = trim($search) !== '' || collect($filters)->contains(fn ($filter) => filled($this->filters[$filter->key] ?? null));
    $colspan = count($columns) + ($selectable ? 1 : 0) + ($actions ? 1 : 0);
@endphp

<div class="card mb-0">
    <div class="card-header d-flex flex-wrap align-items-center gap-3">
        @if ($definition->isSearchable())
            <div class="form-icon flex-grow-1" style="max-width: 22rem">
                <input type="search" class="form-control form-control-icon" placeholder="{{ $definition->searchPlaceholder() }}"
                       aria-label="{{ __('Search') }}" wire:model.live.debounce.350ms="search">
                <i class="ri-search-2-line text-muted"></i>
            </div>
        @endif

        <div class="d-flex flex-wrap align-items-center gap-2 ms-auto">
            @foreach ($filters as $filter)
                <select class="form-select form-select-sm w-auto" aria-label="{{ $filter->label }}" wire:model.live="filters.{{ $filter->key }}">
                    <option value="">{{ __(':label: all', ['label' => $filter->label]) }}</option>
                    @foreach ($filter->getOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            @endforeach

            @if ($selectable && count($selected))
                <div class="dropdown">
                    <button type="button" class="btn btn-sm btn-subtle-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        {{ trans_choice('{1} :count selected|[2,*] :count selected', count($selected), ['count' => count($selected)]) }}
                    </button>
                    <div class="dropdown-menu dropdown-menu-end">
                        @foreach ($bulkActions as $bulk)
                            <button type="button" class="dropdown-item hstack gap-2 text-{{ $bulk->color }}"
                                @if ($bulk->confirm)
                                    x-on:click="SSMS.confirm(@js(['title' => $bulk->label.'?', 'text' => $bulk->confirm, 'confirmText' => $bulk->label, 'cancelText' => __('Cancel'), 'danger' => $bulk->color === 'danger'])).then(ok => ok && $wire.runBulkAction(@js($bulk->name)))"
                                @else
                                    wire:click="runBulkAction(@js($bulk->name))"
                                @endif>
                                <i class="{{ $bulk->icon }}"></i>{{ $bulk->label }}
                            </button>
                        @endforeach
                        <div class="dropdown-divider"></div>
                        <button type="button" class="dropdown-item" wire:click="$set('selected', [])">{{ __('Clear selection') }}</button>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle table-nowrap mb-0">
            <thead class="bg-light bg-opacity-30">
                <tr>
                    @if ($selectable)
                        <th scope="col" style="width: 1%">
                            <input type="checkbox" class="form-check-input" aria-label="{{ __('Select all on this page') }}"
                                   @checked($pageSelected) @disabled($pageKeys === []) wire:click="togglePage(@js($pageKeys))">
                        </th>
                    @endif

                    @foreach ($columns as $column)
                        <th scope="col" class="{{ $column->cellClass() }}"
                            @if ($column->isSortable() && $sortBy === $column->key) aria-sort="{{ $sortDirection === 'asc' ? 'ascending' : 'descending' }}" @endif>
                            @if ($column->isSortable())
                                <button type="button" class="btn btn-link p-0 border-0 text-reset fw-semibold text-decoration-none d-inline-flex align-items-center gap-1"
                                        wire:click="sort(@js($column->key))">
                                    {{ $column->label }}
                                    @if ($sortBy === $column->key)
                                        <i class="{{ $sortDirection === 'asc' ? 'ri-arrow-up-line' : 'ri-arrow-down-line' }} text-primary"></i>
                                    @else
                                        <i class="ri-arrow-up-down-line text-muted opacity-50"></i>
                                    @endif
                                </button>
                            @else
                                {{ $column->label }}
                            @endif
                        </th>
                    @endforeach

                    @if ($actions)
                        <th scope="col" class="text-end">{{ __('Actions') }}</th>
                    @endif
                </tr>
            </thead>

            <tbody wire:loading.class="opacity-50" wire:target="search,sort,filters,perPage,gotoPage,nextPage,previousPage,runAction,runBulkAction,clearFilters">
                @forelse ($rows as $row)
                    <tr wire:key="row-{{ $row->getKey() }}" @class(['table-active' => in_array((string) $row->getKey(), $selected, true)])>
                        @if ($selectable)
                            <td>
                                <input type="checkbox" class="form-check-input" value="{{ $row->getKey() }}" wire:model.live="selected"
                                       aria-label="{{ __('Select row') }}">
                            </td>
                        @endif

                        @foreach ($columns as $column)
                            @php($cell = $column->render($row))
                            <td class="{{ $column->cellClass() }}">{{ filled($cell) ? $cell : '—' }}</td>
                        @endforeach

                        @if ($actions)
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    @foreach ($actions as $action)
                                        @continue(! $action->isAllowed($user, $row))

                                        <button type="button" class="btn btn-subtle-{{ $action->color }} icon-btn-sm" title="{{ $action->label }}" aria-label="{{ $action->label }}"
                                            @if ($action->isModal())
                                                wire:click="$dispatch('open-modal', @js(['component' => $action->modal, 'params' => $action->modalParams($row), 'size' => $action->modalSize]))"
                                            @elseif ($action->confirm)
                                                x-on:click="SSMS.confirm(@js(['title' => $action->confirmTitle ?? $action->label.'?', 'text' => $action->confirm, 'confirmText' => $action->label, 'cancelText' => __('Cancel'), 'danger' => $action->color === 'danger'])).then(ok => ok && $wire.runAction(@js($action->name), @js($row->getKey())))"
                                            @else
                                                wire:click="runAction(@js($action->name), @js($row->getKey()))"
                                            @endif>
                                            <i class="{{ $action->icon }}"></i>
                                        </button>
                                    @endforeach
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $colspan }}" class="p-0">
                            @if ($filtering)
                                <x-empty-state icon="ri-search-eye-line" :title="__('No matching results')" :message="__('Try a different search or clear the filters.')">
                                    <button type="button" class="btn btn-sm btn-light mt-3" wire:click="clearFilters">{{ __('Clear search and filters') }}</button>
                                </x-empty-state>
                            @else
                                <x-empty-state :title="$definition->emptyTitle()" :message="$definition->emptyMessage()" />
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($rows->total() > 0)
        <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-2 text-muted fs-13">
                <label for="per-page-{{ $this->getId() }}" class="mb-0">{{ __('Rows per page') }}</label>
                <select id="per-page-{{ $this->getId() }}" class="form-select form-select-sm w-auto" wire:model.live="perPage">
                    @foreach ($definition->perPageOptions as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </div>
            <div class="data-table-pagination">
                {{ $rows->links() }}
            </div>
        </div>
    @endif
</div>
