<?php

namespace App\Tables;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * One column of a data table. The key is a dotted path into the row
 * ("name", "user.email"); searching and sorting use it as a database
 * column unless told otherwise.
 */
class Column
{
    public string $label;

    private bool|Closure $sortable = false;

    private bool|string $searchable = false;

    private ?Closure $formatter = null;

    private ?string $view = null;

    private string $class = '';

    private bool $hiddenOnMobile = false;

    private bool $virtual = false;

    final public function __construct(public readonly string $key, ?string $label = null)
    {
        $this->label = $label ?? Str::headline(Str::afterLast($key, '.'));
    }

    public static function make(string $key, ?string $label = null): static
    {
        return new static($key, $label);
    }

    /**
     * Sortable by this column's key, or by a custom callback
     * fn (Builder $query, string $direction).
     */
    public function sortable(bool|Closure $sortable = true): static
    {
        $this->sortable = $sortable;

        return $this;
    }

    /**
     * Searchable by this column's key ("user.name" searches the relation),
     * or by another column name.
     */
    public function searchable(bool|string $searchable = true): static
    {
        $this->searchable = $searchable;

        return $this;
    }

    /**
     * Custom display: fn (mixed $value, Model $row): string|Htmlable.
     * Plain strings are escaped; return an HtmlString for markup.
     */
    public function format(Closure $formatter): static
    {
        $this->formatter = $formatter;

        return $this;
    }

    /**
     * Render the cell with a Blade view, receiving $row, $value and $column.
     */
    public function view(string $view): static
    {
        $this->view = $view;

        return $this;
    }

    /**
     * Show a backed enum with its label and colour as a Herozi badge.
     */
    public function badge(): static
    {
        return $this->format(function (mixed $value) {
            if (! $value instanceof BackedEnum) {
                return $value;
            }

            $color = method_exists($value, 'color') ? $value->color() : 'secondary';
            $label = method_exists($value, 'label') ? $value->label() : $value->value;

            return new HtmlString(sprintf('<span class="badge bg-%1$s-subtle text-%1$s">%2$s</span>', e($color), e($label)));
        });
    }

    /**
     * Format a date in the school's date format.
     */
    public function date(?string $format = null): static
    {
        return $this->format(function (mixed $value) use ($format) {
            if (blank($value)) {
                return null;
            }

            return Carbon::parse($value)->format($format ?? tenant()?->setting('date_format') ?? 'd M Y');
        });
    }

    /**
     * Format a timestamp in the school's timezone, date and time formats.
     */
    public function dateTime(): static
    {
        return $this->format(function (mixed $value) {
            if (blank($value)) {
                return null;
            }

            $school = tenant();
            $time = Carbon::parse($value);

            return $school
                ? $school->localTime($time)->format($school->setting('date_format').', '.$school->setting('time_format'))
                : $time->format('d M Y, H:i');
        });
    }

    /**
     * Format an amount with the school's currency symbol.
     */
    public function money(): static
    {
        return $this->format(fn (mixed $value) => blank($value) ? null : (tenant()?->setting('currency_symbol') ?? '').number_format((float) $value, 2))
            ->alignEnd();
    }

    public function boolean(string $yes = 'Yes', string $no = 'No'): static
    {
        return $this->format(fn (mixed $value) => new HtmlString($value
            ? '<i class="ri-checkbox-circle-fill text-success"></i> <span class="visually-hidden">'.e(__($yes)).'</span>'
            : '<i class="ri-close-circle-line text-muted"></i> <span class="visually-hidden">'.e(__($no)).'</span>'));
    }

    public function alignEnd(): static
    {
        return $this->class('text-end');
    }

    public function class(string $class): static
    {
        $this->class = trim($this->class.' '.$class);

        return $this;
    }

    public function hiddenOnMobile(): static
    {
        $this->hiddenOnMobile = true;

        return $this;
    }

    /**
     * The key isn't an attribute: the formatter or view works the cell out
     * from the row (it receives null as the value).
     */
    public function virtual(): static
    {
        $this->virtual = true;

        return $this;
    }

    public function isSortable(): bool
    {
        return $this->sortable !== false;
    }

    public function isSearchable(): bool
    {
        return $this->searchable !== false;
    }

    public function cellClass(): string
    {
        return trim($this->class.($this->hiddenOnMobile ? ' d-none d-md-table-cell' : ''));
    }

    public function value(Model $row): mixed
    {
        return $this->virtual ? null : data_get($row, $this->key);
    }

    public function render(Model $row): string|Htmlable|null
    {
        $value = $this->value($row);

        if ($this->view) {
            return new HtmlString(view($this->view, ['row' => $row, 'value' => $value, 'column' => $this])->render());
        }

        if ($this->formatter) {
            return ($this->formatter)($value, $row);
        }

        if ($value instanceof BackedEnum) {
            return method_exists($value, 'label') ? $value->label() : (string) $value->value;
        }

        return $value === null ? null : (string) $value;
    }

    public function applySort(Builder $query, string $direction): void
    {
        if ($this->sortable instanceof Closure) {
            ($this->sortable)($query, $direction);

            return;
        }

        $query->orderBy($query->qualifyColumn($this->key), $direction);
    }

    /**
     * Adds an OR condition to the table's grouped search clause.
     */
    public function applySearch(Builder $query, string $search): void
    {
        $path = is_string($this->searchable) ? $this->searchable : $this->key;
        $term = '%'.addcslashes($search, '%_\\').'%';

        if (str_contains($path, '.')) {
            $relation = Str::beforeLast($path, '.');
            $column = Str::afterLast($path, '.');

            $query->orWhereHas($relation, fn (Builder $related) => $related->where($related->qualifyColumn($column), 'like', $term));

            return;
        }

        $query->orWhere($query->qualifyColumn($path), 'like', $term);
    }
}
