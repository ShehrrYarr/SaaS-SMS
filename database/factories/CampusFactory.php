<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create inside tenancy()->run($school, ...): school_id comes from the context.
 *
 * @extends Factory<\App\Models\Campus>
 */
class CampusFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city().' Campus',
            'is_main' => false,
            'status' => RecordStatus::Active,
        ];
    }
}
