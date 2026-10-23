<?php

namespace App\Tables;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * A per-row action button: open a modal form, or run a server handler
 * (optionally after a confirmation dialog).
 */
class Action
{
    public string $icon = 'ri-more-line';

    public string $color = 'primary';

    public ?string $modal = null;

    public string $modalSize = 'md';

    public ?string $confirm = null;

    public ?string $confirmTitle = null;

    private ?Closure $modalParams = null;

    private ?Closure $url = null;

    private ?Closure $handler = null;

    private string|Closure|null $permission = null;

    private ?Closure $visible = null;

    private string|Closure|null $success = null;

    final public function __construct(public readonly string $name, public readonly string $label) {}

    public static function make(string $name, string $label): static
    {
        return new static($name, $label);
    }

    /**
     * Ready-made edit action that opens a modal form with the row id.
     */
    public static function edit(string $component, string $param, ?string $permission = null): static
    {
        return static::make('edit', __('Edit'))
            ->icon('ri-pencil-line')
            ->modal($component, fn (Model $row) => [$param => $row->getKey()])
            ->can($permission);
    }

    /**
     * Ready-made delete action with a confirmation dialog.
     */
    public static function delete(?string $permission = null, ?string $confirm = null): static
    {
        return static::make('delete', __('Delete'))
            ->icon('ri-delete-bin-line')
            ->color('danger')
            ->confirm($confirm ?? __('This cannot be undone.'), __('Delete this record?'))
            ->can($permission)
            ->handle(fn (Model $row) => $row->delete())
            ->success(__('Deleted.'));
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

    /**
     * Open a Livewire form (a "forms.*" component) in the modal host.
     *
     * @param  Closure(Model): array<string, mixed>|null  $params
     */
    public function modal(string $component, ?Closure $params = null, string $size = 'md'): static
    {
        $this->modal = $component;
        $this->modalParams = $params;
        $this->modalSize = $size;

        return $this;
    }

    /**
     * Link to another page (opened with wire:navigate).
     *
     * @param  Closure(Model): string  $url
     */
    public function url(Closure $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function confirm(string $message, ?string $title = null): static
    {
        $this->confirm = $message;
        $this->confirmTitle = $title;

        return $this;
    }

    /**
     * @param  Closure(Model): mixed  $handler  Throw ActionFailed to show the user an error.
     */
    public function handle(Closure $handler): static
    {
        $this->handler = $handler;

        return $this;
    }

    /**
     * Permission name, or fn (User $user, Model $row): bool.
     */
    public function can(string|Closure|null $permission): static
    {
        $this->permission = $permission;

        return $this;
    }

    /**
     * @param  Closure(Model): bool  $visible
     */
    public function visible(Closure $visible): static
    {
        $this->visible = $visible;

        return $this;
    }

    /**
     * Toast message after the handler succeeds: string or fn (Model $row): string.
     */
    public function success(string|Closure $message): static
    {
        $this->success = $message;

        return $this;
    }

    public function isModal(): bool
    {
        return $this->modal !== null;
    }

    public function isLink(): bool
    {
        return $this->url !== null;
    }

    public function urlFor(Model $row): string
    {
        return ($this->url)($row);
    }

    /**
     * @return array<string, mixed>
     */
    public function modalParams(Model $row): array
    {
        return $this->modalParams ? ($this->modalParams)($row) : [];
    }

    public function isAllowed(?User $user, Model $row): bool
    {
        if ($this->visible && ! ($this->visible)($row)) {
            return false;
        }

        if ($this->permission === null) {
            return true;
        }

        if ($this->permission instanceof Closure) {
            return $user !== null && ($this->permission)($user, $row);
        }

        return (bool) $user?->can($this->permission);
    }

    public function run(Model $row): ?string
    {
        if ($this->handler) {
            ($this->handler)($row);
        }

        return $this->success instanceof Closure ? ($this->success)($row) : $this->success;
    }
}
