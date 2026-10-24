<?php

namespace App\Support;

use App\Exceptions\DemoLocked;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * The public demo (config/demo.php): one-click sign-in to the demo school,
 * and the locks that keep it usable for the next visitor.
 *
 * Locks only apply to web requests in the demo school. The console (the
 * nightly `demo:seed --fresh`, the scheduler) is never locked.
 */
class Demo
{
    private static ?bool $enforce = null;

    public static function enabled(): bool
    {
        return (bool) config('demo.enabled');
    }

    public static function slug(): string
    {
        return (string) config('demo.school');
    }

    public static function school(): ?School
    {
        return static::enabled() ? School::query()->where('slug', static::slug())->first() : null;
    }

    /**
     * The landing page's "Enter as" accounts.
     *
     * @return array<string, array{username: string, label: string, description: string, icon: string}>
     */
    public static function accounts(): array
    {
        return config('demo.accounts', []);
    }

    /**
     * Whether visitors' changes to sign-ins and settings are blocked: a web
     * request in the demo school.
     */
    public static function locked(?School $school = null): bool
    {
        $school ??= tenant();

        return static::enabled()
            && $school?->slug === static::slug()
            && (static::$enforce ?? ! app()->runningInConsole());
    }

    /**
     * Tests: true/false to force the locks on or off, null for the default.
     */
    public static function enforceLocks(?bool $enforce): void
    {
        static::$enforce = $enforce;
    }

    /**
     * Model guards, registered at boot. Every one-click account must keep
     * working with the demo password, and the school keeps its name and logo.
     */
    public static function guardModels(): void
    {
        User::updating(function (User $user) {
            if (! static::locked() || $user->school_id !== tenant()->getKey()) {
                return;
            }

            // A rehash of the same demo password is fine; a new password is not.
            if ($user->isDirty('username') || ($user->isDirty('password') && ! Hash::check((string) config('demo.password'), $user->password))) {
                throw DemoLocked::signIns();
            }

            if ($user->isDirty('status') && static::isShowcased($user)) {
                throw DemoLocked::showcasedAccount();
            }
        });

        User::deleting(function (User $user) {
            if (static::locked() && $user->school_id === tenant()->getKey() && static::isShowcased($user)) {
                throw DemoLocked::showcasedAccount();
            }
        });

        // The School settings page is locked as a whole; module options kept in
        // `settings` (bell schedule, library rules) stay open to visitors.
        School::updating(function (School $school) {
            if (static::locked($school) && $school->isDirty(['name', 'slug', 'logo', 'email', 'phone', 'address', 'city', 'country'])) {
                throw DemoLocked::settings();
            }
        });
    }

    private static function isShowcased(User $user): bool
    {
        return in_array($user->getOriginal('username'), array_column(static::accounts(), 'username'), true);
    }
}
