<?php

namespace Database\Factories;

use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Season>
 */
class SeasonFactory extends Factory
{
    /**
     * A running season that started a week ago.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => fake()->unique()->numberBetween(100, 100000), // clear of season 1, which the migration opens
            'starts_at' => now()->subWeek(),
            'ends_at' => null,
        ];
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes) => ['starts_at' => now()->subMonths(2), 'ends_at' => now()->subMonth()]);
    }
}
