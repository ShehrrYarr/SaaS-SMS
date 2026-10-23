<?php

namespace App\Support\Navigation;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Builds a sidebar menu from config/navigation.php for one user: only items
 * they are allowed to see, whose screens exist, with the active trail marked.
 *
 * Each returned entry is either ['heading' => string] or
 * ['label', 'icon', 'url', 'active', 'children'].
 */
class Menu
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function build(string $menu, ?User $user): array
    {
        return static::withoutEmptyHeadings(static::filter(config("navigation.{$menu}", []), $user));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function filter(array $items, ?User $user): array
    {
        $visible = [];

        foreach ($items as $item) {
            if (isset($item['heading'])) {
                $visible[] = ['heading' => __($item['heading'])];

                continue;
            }

            if (isset($item['can']) && ! $user?->can($item['can'])) {
                continue;
            }

            if (isset($item['children'])) {
                $children = static::filter($item['children'], $user);

                if ($children !== []) {
                    $visible[] = static::entry($item, null, collect($children)->contains('active', true), $children);
                }

                continue;
            }

            if (Route::has($item['route'])) {
                $visible[] = static::entry($item, route($item['route']), request()->routeIs(static::activePattern($item)));
            }
        }

        return $visible;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private static function entry(array $item, ?string $url, bool $active, array $children = []): array
    {
        return [
            'label' => __($item['label']),
            'icon' => $item['icon'] ?? null,
            'url' => $url,
            'active' => $active,
            'children' => $children,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function activePattern(array $item): string
    {
        return $item['active'] ?? (Str::endsWith($item['route'], '.index')
            ? Str::beforeLast($item['route'], '.index').'.*'
            : $item['route']);
    }

    /**
     * Drop headings with no items under them.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function withoutEmptyHeadings(array $items): array
    {
        $result = [];

        foreach ($items as $index => $item) {
            $next = $items[$index + 1] ?? null;

            if (isset($item['heading']) && ($next === null || isset($next['heading']))) {
                continue;
            }

            $result[] = $item;
        }

        return $result;
    }
}
