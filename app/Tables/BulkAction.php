<?php

namespace App\Tables;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * An action applied to the selected rows.
 */
class BulkAction
{
    public string $icon = 'ri-checkbox-multiple-line';

    public string $color = 'primary';

    public ?string $confirm = null;

    private ?Closure $handler = null;

    private ?string $permission = null;

    private string|Closure|null $success = null;

    final public function __construct(public readonly string $name, public readonly string $label) {}

    public static function make(string $name, string $label): static
    {
        return new static($name, $label);
    }

    /**
     * Ready-made bulk delete, with confirmation. Rows the user may not delete
     * individually are left alone by passing $deletable.
     *
     * @param  (Closure(Model): bool)|null  $deletable
     */
    public static function delete(?string $permission = null, ?Closure $deletable = null): static
    {
        return static::make('delete', __('Delete selected'))
            ->icon('ri-delete-bin-line')
            ->color('danger')
            ->confirm(__('Delete the selected records? This cannot be undone.'))
            ->can($permission)
            ->handle(function (Collection $rows) use ($deletable): int {
                $rows = $deletable ? $rows->filter($deletable) : $rows;
                $rows->each->delete();

                return $rows->count();
            })
            ->success(fn (int $count) => trans_choice('{1} :count record deleted.|[2,*] :count records deleted.', $count, ['count' => $count]));
    }

    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function color(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function confirm(string $message): static
    {
        $this->confirm = $message;

        return $this;
    }

    /**
     * @param  Closure(Collection<int, Model>): mixed  $handler  Its return value is passed to success().
     */
    public function handle(Closure $handler): static
    {
        $this->handler = $handler;

        return $this;
    }

    public function can(?string $permission): static
    {
        $this->permission = $permission;

        return $this;
    }

    /**
     * Toast after success: string, or fn (mixed $handlerResult): string.
     */
    public function success(string|Closure $message): static
    {
        $this->success = $message;

        return $this;
    }

    public function isAllowed(?User $user): bool
    {
        return $this->permission === null || (bool) $user?->can($this->permission);
    }

    /**
     * @param  Collection<int, Model>  $rows
     */
    public function run(Collection $rows): ?string
    {
        $result = $this->handler ? ($this->handler)($rows) : null;

        return $this->success instanceof Closure ? ($this->success)($result) : $this->success;
    }
}
