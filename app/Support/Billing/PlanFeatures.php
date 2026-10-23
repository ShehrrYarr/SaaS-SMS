<?php

namespace App\Support\Billing;

use App\Models\Plan;
use Illuminate\Support\Str;

/**
 * Reads config/plans.php: which optional features exist, and which features
 * a permission depends on.
 *
 * A permission belonging to a feature module ("payroll.generate") or a
 * feature portal ("portal.accounting") is only granted in a school whose plan
 * includes that feature, whatever the user's roles say.
 */
class PlanFeatures
{
    /**
     * permission prefix => features, any of which unlocks it.
     *
     * @var array<string, list<string>>|null
     */
    private static ?array $requirements = null;

    /**
     * @return array<string, string> feature key => label
     */
    public static function options(): array
    {
        return array_map(fn (array $feature) => __($feature['label']), config('plans.features'));
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(config('plans.features'));
    }

    /**
     * Features any one of which unlocks the permission; null when the
     * permission is core (or isn't a school permission at all).
     *
     * @return list<string>|null
     */
    public static function requiredFor(string $permission): ?array
    {
        return static::requirements()[static::prefix($permission)] ?? null;
    }

    public static function allows(?Plan $plan, string $permission): bool
    {
        if ($plan === null || $plan->features === null) {
            return true;
        }

        $required = static::requiredFor($permission);

        return $required === null || array_intersect($required, $plan->features) !== [];
    }

    /**
     * Forget the cached map (tests that change config/plans.php).
     */
    public static function flush(): void
    {
        static::$requirements = null;
    }

    /**
     * "payroll.generate" => "payroll", "portal.hr" => "portal.hr".
     */
    private static function prefix(string $permission): string
    {
        return str_starts_with($permission, 'portal.') ? $permission : Str::before($permission, '.');
    }

    /**
     * @return array<string, list<string>>
     */
    private static function requirements(): array
    {
        if (static::$requirements !== null) {
            return static::$requirements;
        }

        $map = [];

        foreach (config('plans.features') as $feature => $definition) {
            foreach ($definition['modules'] ?? [] as $module) {
                $map[$module][] = $feature;
            }

            foreach ($definition['portals'] ?? [] as $portal) {
                $map["portal.{$portal}"][] = $feature;
            }
        }

        return static::$requirements = $map;
    }
}
