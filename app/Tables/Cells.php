<?php

namespace App\Tables;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Small HTML cells shared by the people tables.
 */
class Cells
{
    /**
     * Avatar (photo or initials), name and a muted second line, optionally a link.
     */
    public static function person(?User $user, ?string $subtitle = null, ?string $url = null): HtmlString
    {
        if (! $user) {
            return new HtmlString('<span class="text-muted">—</span>');
        }

        $avatar = $user->avatarUrl()
            ? '<img src="'.e($user->avatarUrl()).'" alt="">'
            : e($user->initials());

        $inner = '<span class="entity-avatar rounded-circle">'.$avatar.'</span>'
            .'<span class="min-w-0"><span class="d-block fw-medium text-body text-truncate">'.e($user->name).'</span>'
            .($subtitle ? '<small class="d-block text-muted text-truncate">'.e($subtitle).'</small>' : '')
            .'</span>';

        return new HtmlString($url
            ? '<a href="'.e($url).'" wire:navigate class="d-flex align-items-center gap-3 text-reset text-decoration-none">'.$inner.'</a>'
            : '<span class="d-flex align-items-center gap-3">'.$inner.'</span>');
    }

    /**
     * Name and phone (or email) on two lines.
     */
    public static function contact(?User $user, ?string $extra = null): ?HtmlString
    {
        if (! $user) {
            return null;
        }

        $reach = $user->phone ?: $user->email;

        return new HtmlString('<span class="d-block text-body">'.e($user->name).($extra ? ' <small class="text-muted">('.e($extra).')</small>' : '').'</span>'
            .($reach ? '<small class="d-block text-muted">'.e($reach).'</small>' : ''));
    }

    /**
     * @param  Collection<int, string>|list<string>  $labels
     */
    public static function badges(Collection|array $labels, string $color = 'primary', ?string $empty = null): HtmlString
    {
        $labels = collect($labels)->filter();

        if ($labels->isEmpty()) {
            return new HtmlString($empty ? '<span class="text-muted">'.e($empty).'</span>' : '');
        }

        return new HtmlString($labels->map(fn (string $label) => '<span class="badge bg-'.e($color).'-subtle text-'.e($color).' me-1">'.e($label).'</span>')->implode(''));
    }
}
