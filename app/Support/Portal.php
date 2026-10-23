<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The school portals (/admin, /teacher, /hr, /accounting, /student, /parent).
 *
 * The current portal is recorded by EnsurePortalAccess, which also runs on
 * Livewire update requests, so it is known inside components too.
 */
class Portal
{
    private const CURRENT = 'portal.current';

    public static function setCurrent(string $portal): void
    {
        app()->instance(self::CURRENT, $portal);
    }

    public static function current(): ?string
    {
        return app()->bound(self::CURRENT) ? app(self::CURRENT) : null;
    }

    public static function label(string $portal): string
    {
        return __(config("access.portals.{$portal}.label", $portal));
    }

    public static function icon(string $portal): string
    {
        return config("access.portals.{$portal}.icon", 'ri-apps-line');
    }

    /**
     * A route inside a portal: Portal::route('hr', 'staff.show', $id) → hr.staff.show.
     * Screens shared by two portals (staff in Admin and HR) link within the
     * portal they were opened in.
     */
    public static function route(?string $portal, string $name, mixed $parameters = []): string
    {
        return route(($portal ?? static::current() ?? 'admin').'.'.$name, $parameters);
    }

    public static function dashboardRoute(string $portal): string
    {
        return "{$portal}.dashboard";
    }

    /**
     * The first staff portal (not student or parent) the user can enter.
     */
    public static function staffPortalFor(User $user): ?string
    {
        foreach ($user->accessiblePortals() as $portal) {
            if (! config("access.portals.{$portal}.self_service")) {
                return $portal;
            }
        }

        return null;
    }

    /**
     * A staff member's own page (my-leave, my-payslips) in their first staff
     * portal, for links in notifications; the school home when there is none.
     */
    public static function selfServiceUrl(User $user, string $page): string
    {
        $portal = static::staffPortalFor($user);

        return $portal && Route::has("{$portal}.{$page}") ? route("{$portal}.{$page}") : route('tenant.home');
    }

    /**
     * Where a user lands after signing in: the first portal they can enter.
     */
    public static function homeFor(User $user): ?string
    {
        $portal = $user->accessiblePortals()[0] ?? null;

        return $portal ? route(static::dashboardRoute($portal)) : null;
    }
}
