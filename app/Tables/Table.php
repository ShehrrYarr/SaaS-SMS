<?php

namespace App\Tables;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Definition of a list screen rendered by the shared data-table component
 * (resources/views/livewire/components/data-table.blade.php).
 *
 * A subclass describes the query, columns, filters and actions; the
 * component owns the state (search, sort, filters, page, selection). Table
 * objects are rebuilt on every request and never serialised, so closures
 * in column formatters and action handlers are fine.
 *
 * Every query must go through query(): it is tenant-scoped by the models'
 * global scope, and row/bulk actions re-resolve their records through it, so
 * a client can never act on rows it can't see.
 */
abstract class Table
{
    public int $perPage = 10;

    /** @var list<int> */
    public array $perPageOptions = [10, 25, 50, 100];

    /** @var array<string, mixed> */
    protected array $params = [];

    abstract public function query(): Builder;

    /**
     * @return list<Column>
     */
    abstract public function columns(): array;

    /**
     * @return list<Filter>
     */
    public function filters(): array
    {
        return [];
    }

    /**
     * @return list<Action>
     */
    public function actions(): array
    {
        return [];
    }

    /**
     * @return list<BulkAction>
     */
    public function bulkActions(): array
    {
        return [];
    }

    /**
     * Permission required to see this table at all.
     */
    public function permission(): ?string
    {
        return null;
    }

    /**
     * @return array{0: string|null, 1: 'asc'|'desc'}
     */
    public function defaultSort(): array
    {
        return [null, 'asc'];
    }

    public function searchPlaceholder(): string
    {
        return __('Search…');
    }

    public function emptyTitle(): string
    {
        return __('Nothing here yet');
    }

    public function emptyMessage(): ?string
    {
        return null;
    }

    /**
     * Context passed from the page, e.g. ['class_id' => 4] for a nested list.
     *
     * @param  array<string, mixed>  $params
     */
    public function withParams(array $params): static
    {
        $this->params = $params;

        return $this;
    }

    public function authorize(): void
    {
        if ($permission = $this->permission()) {
            Gate::authorize($permission);
        }
    }

    public function isSearchable(): bool
    {
        return collect($this->columns())->contains(fn (Column $column) => $column->isSearchable());
    }

    public function column(string $key): ?Column
    {
        return collect($this->columns())->first(fn (Column $column) => $column->key === $key);
    }

    public function filter(string $key): ?Filter
    {
        return collect($this->filters())->first(fn (Filter $filter) => $filter->key === $key);
    }

    public function action(string $name): ?Action
    {
        return collect($this->actions())->first(fn (Action $action) => $action->name === $name);
    }

    public function bulkAction(string $name): ?BulkAction
    {
        return collect($this->bulkActions())->first(fn (BulkAction $action) => $action->name === $name);
    }

    /**
     * Find one row the current user can see (used by row actions).
     */
    public function findRow(int|string $key): Model
    {
        return $this->query()->findOrFail($key);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function rows(string $search, ?string $sortBy, string $direction, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->query();

        $this->applySearch($query, trim($search));

        foreach ($this->filters() as $filter) {
            $value = $filters[$filter->key] ?? null;

            if (! blank($value)) {
                $filter->apply($query, $value);
            }
        }

        if ($sortBy && ($column = $this->column($sortBy)) && $column->isSortable()) {
            $column->applySort($query, $direction === 'desc' ? 'desc' : 'asc');
        }

        $perPage = in_array($perPage, $this->perPageOptions, true) ? $perPage : $this->perPage;

        return $query->paginate($perPage);
    }

    protected function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $columns = collect($this->columns())->filter(fn (Column $column) => $column->isSearchable());

        $query->where(function (Builder $query) use ($columns, $search) {
            foreach ($columns as $column) {
                $column->applySearch($query, $search);
            }
        });
    }
}
