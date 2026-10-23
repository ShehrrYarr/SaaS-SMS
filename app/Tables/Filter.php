<?php

namespace App\Tables;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * A dropdown filter above a data table.
 */
class Filter
{
    /** @var array<string, string> value => label */
    private array $options = [];

    private ?Closure $using = null;

    final public function __construct(public readonly string $key, public readonly string $label) {}

    public static function make(string $key, string $label): static
    {
        return new static($key, $label);
    }

    /**
     * @param  array<string, string>|class-string<BackedEnum>  $options  value => label, or an enum class
     */
    public function options(array|string $options): static
    {
        $this->options = is_string($options) ? $options::options() : $options;

        return $this;
    }

    /**
     * Custom query: fn (Builder $query, string $value). Defaults to where(key, value).
     */
    public function query(Closure $using): static
    {
        $this->using = $using;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function apply(Builder $query, mixed $value): void
    {
        // Ignore values that aren't offered: the client can't widen a filter.
        if ($this->options !== [] && ! array_key_exists((string) $value, $this->options)) {
            return;
        }

        if ($this->using) {
            ($this->using)($query, $value);

            return;
        }

        $query->where($query->qualifyColumn($this->key), $value);
    }
}
