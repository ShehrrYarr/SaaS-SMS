<?php

namespace Database\Factories;

use App\Enums\SchoolStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\School>
 */
class SchoolFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->lastName().' Academy';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'email' => fake()->safeEmail(),
            'status' => SchoolStatus::Active,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => SchoolStatus::Suspended, 'suspended_at' => now()]);
    }
}
