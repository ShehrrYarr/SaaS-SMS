<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word()).' Plan';

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'price' => 100,
            'currency' => 'USD',
            'billing_cycle' => BillingCycle::Monthly,
            'max_students' => null,
            'max_staff' => null,
            'features' => null,
            'trial_days' => 14,
            'is_active' => true,
        ];
    }

    /**
     * @param  list<string>  $features
     */
    public function features(array $features): static
    {
        return $this->state(fn () => ['features' => $features]);
    }

    public function retired(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
