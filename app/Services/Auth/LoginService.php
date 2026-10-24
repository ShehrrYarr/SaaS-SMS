<?php

namespace App\Services\Auth;

use App\Enums\UserType;
use App\Models\School;
use App\Models\User;
use App\Support\Portal;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Signs a user in to one school (or, with no school, the platform console).
 *
 * The login field accepts an email, phone number or username. Lookups are
 * always restricted to the given school, so the same credentials at another
 * school never match.
 *
 * Failed attempts are limited per account (guessing one password) and per IP
 * address across every account and school (one password tried on many
 * accounts). A successful sign-in only clears the account's counter.
 */
class LoginService
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    public const MAX_ATTEMPTS_PER_IP = 30;

    public const IP_DECAY_SECONDS = 900;

    /**
     * @throws ValidationException
     */
    public function attempt(?School $school, string $login, string $password, bool $remember = false): User
    {
        $login = trim($login);
        $throttleKey = $this->throttleKey($school, $login);

        $this->ensureIsNotRateLimited($throttleKey, self::MAX_ATTEMPTS);
        $this->ensureIsNotRateLimited($this->ipThrottleKey(), self::MAX_ATTEMPTS_PER_IP);

        $credentials = [
            'school_id' => $school?->getKey(),
            'identity' => fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('email', $login)
                ->orWhere('username', $login)
                ->orWhere('phone', $login)),
            'password' => $password,
        ];

        if ($school === null) {
            $credentials['user_type'] = UserType::SuperAdmin->value;
        }

        if (! Auth::guard('web')->attempt($credentials, $remember)) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);
            RateLimiter::hit($this->ipThrottleKey(), self::IP_DECAY_SECONDS);

            throw ValidationException::withMessages(['login' => __('auth.failed')]);
        }

        RateLimiter::clear($throttleKey);

        /** @var User $user */
        $user = Auth::guard('web')->user();

        if (! $user->isActive()) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'login' => __('This account is not active. Please contact your school administrator.'),
            ]);
        }

        session()->regenerate();

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => request()->ip()])->saveQuietly();

        return $user;
    }

    /**
     * Where to send a user who just signed in.
     */
    public function redirectPath(User $user): string
    {
        if ($user->isSuperAdmin()) {
            return route('central.dashboard');
        }

        // Only honour an intended URL inside this school.
        $intended = session()->pull('url.intended');
        $schoolPrefix = '/'.tenant()->slug.'/';

        if (is_string($intended) && str_starts_with(parse_url($intended, PHP_URL_PATH) ?? '', $schoolPrefix)) {
            return $intended;
        }

        return Portal::homeFor($user) ?? route('tenant.home');
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(string $throttleKey, int $maxAttempts): void
    {
        if (! RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($throttleKey);

        throw ValidationException::withMessages([
            'login' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
        ]);
    }

    private function ipThrottleKey(): string
    {
        return 'login-ip|'.request()->ip();
    }

    private function throttleKey(?School $school, string $login): string
    {
        return implode('|', ['login', $school?->getKey() ?? 'central', Str::lower($login), request()->ip()]);
    }
}
