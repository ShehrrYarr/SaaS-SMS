<?php

namespace App\Support\Access;

use InvalidArgumentException;

/**
 * Reads config/access.php: the permission names, how they group into modules
 * and portals, and how role definitions expand into concrete permissions.
 */
class PermissionCatalog
{
    /**
     * @return list<string>
     */
    public static function platformPermissions(): array
    {
        return array_keys(config('access.platform'));
    }

    /**
     * @return list<string>
     */
    public static function portalPermissions(): array
    {
        return array_map(
            fn (string $portal) => static::portalPermission($portal),
            array_keys(config('access.portals')),
        );
    }

    /**
     * Every permission that can be granted inside a school.
     *
     * @return list<string>
     */
    public static function schoolPermissions(): array
    {
        $permissions = static::portalPermissions();

        foreach (config('access.modules') as $module => $definition) {
            foreach ($definition['actions'] as $action) {
                $permissions[] = "{$module}.{$action}";
            }
        }

        return $permissions;
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [...static::platformPermissions(), ...static::schoolPermissions()];
    }

    public static function portalPermission(string $portal): string
    {
        return "portal.{$portal}";
    }

    /**
     * Expand role patterns into concrete school permissions.
     *
     * "*" is every school permission, and "homework.*" is every action of the
     * homework module. Unknown names throw, so a typo in config fails loudly.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public static function expand(array $patterns): array
    {
        $known = static::schoolPermissions();
        $expanded = [];

        foreach ($patterns as $pattern) {
            if ($pattern === '*') {
                return $known;
            }

            if (str_ends_with($pattern, '.*')) {
                $prefix = substr($pattern, 0, -1);
                $matches = array_filter($known, fn (string $name) => str_starts_with($name, $prefix));

                throw_if($matches === [], InvalidArgumentException::class, "Unknown permission module [{$pattern}].");

                array_push($expanded, ...$matches);

                continue;
            }

            throw_unless(in_array($pattern, $known, true), InvalidArgumentException::class, "Unknown permission [{$pattern}].");

            $expanded[] = $pattern;
        }

        return array_values(array_unique($expanded));
    }
}
