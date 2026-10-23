<?php

namespace Database\Factories;

use App\Enums\AcademicPeriodStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create inside tenancy()->run($school, ...): school_id comes from the context.
 *
 * @extends Factory<\App\Models\AcademicYear>
 */
class AcademicYearFactory extends Factory
{
    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(1990, 2090);

        return [
            'name' => $year.'-'.($year + 1),
            'start_date' => "{$year}-08-01",
            'end_date' => ($year + 1).'-06-30',
            'status' => AcademicPeriodStatus::Upcoming,
        ];
    }

    public function current(): static
    {
        return $this->state(fn () => ['status' => AcademicPeriodStatus::Current]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => AcademicPeriodStatus::Archived]);
    }
}
