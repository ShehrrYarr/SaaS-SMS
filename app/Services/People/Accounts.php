<?php

namespace App\Services\People;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Support\Passwords;
use Illuminate\Support\Str;

/**
 * Sign-in accounts for the people a school adds: students, parents, staff.
 *
 * There is no email, so every new account gets a generated temporary
 * password that the administrator hands over; it must be changed at first
 * sign-in. Usernames are made from the name when none is given.
 */
class Accounts
{
    /**
     * Create an account in the active school with its built-in role(s).
     *
     * @param  array{name: string, username?: string|null, email?: string|null, phone?: string|null, avatar?: string|null}  $attributes
     * @param  string|list<string|int>  $roles
     */
    public function create(UserType $type, array $attributes, string|array $roles, ?string $password = null): NewAccount
    {
        $password ??= Passwords::generate();

        $user = User::query()->create([
            'name' => trim($attributes['name']),
            'username' => filled($attributes['username'] ?? null) ? $attributes['username'] : $this->uniqueUsername($attributes['name']),
            'email' => $this->blankToNull($attributes['email'] ?? null),
            'phone' => $this->blankToNull($attributes['phone'] ?? null),
            'avatar' => $attributes['avatar'] ?? null,
            'password' => $password,
            'user_type' => $type,
            'status' => UserStatus::Active,
            'must_change_password' => true,
        ]);

        $user->assignRole($roles);

        return new NewAccount($user, new Credentials($user->name, $user->username, $password, $type->label()));
    }

    /**
     * "Sara Khan" → "sara.khan", then "sara.khan2", ... Deleted accounts keep
     * their usernames, so those are skipped too.
     */
    public function uniqueUsername(string $name, string $fallback = 'user'): string
    {
        $base = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', '.')->trim('.')->limit(40, '')->toString();
        $base = $base !== '' ? $base : $fallback;

        $taken = User::query()->withTrashed()
            ->where(fn ($query) => $query->where('username', $base)->orWhere('username', 'like', addcslashes($base, '%_\\').'%'))
            ->pluck('username')
            ->map(fn ($username) => Str::lower((string) $username))
            ->all();

        $candidate = $base;
        $suffix = 1;

        while (in_array($candidate, $taken, true)) {
            $candidate = $base.(++$suffix);
        }

        return $candidate;
    }

    /**
     * Whether a sign-in identifier (username, email or phone) is free in the
     * active school, optionally ignoring one account.
     */
    public function isAvailable(string $column, ?string $value, ?int $ignoreUserId = null): bool
    {
        if (blank($value)) {
            return true;
        }

        return User::query()->withTrashed()
            ->where($column, $value)
            ->when($ignoreUserId, fn ($query) => $query->whereKeyNot($ignoreUserId))
            ->doesntExist();
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
