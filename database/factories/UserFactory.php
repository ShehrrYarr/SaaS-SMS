<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Enums\UserType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Users are tenant-owned: create them inside tenancy()->run($school, ...),
 * or use superAdmin() inside tenancy()->runCentral(...).
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'user_type' => UserType::Staff,
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    public function type(UserType $type): static
    {
        return $this->state(fn () => ['user_type' => $type]);
    }

    public function superAdmin(): static
    {
        return $this->type(UserType::SuperAdmin);
    }
}
