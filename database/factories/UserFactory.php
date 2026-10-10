<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
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
            'name' => fake()->unique()->regexify('[a-z]{4,10}[0-9]{2}'),
            'password' => static::$password ??= Hash::make('password'),
            'rating' => 1000,
            'remember_token' => Str::random(10),
        ];
    }

    public function rated(int $rating): static
    {
        return $this->state(fn (array $attributes) => ['rating' => $rating]);
    }

    /**
     * A player with some finished games behind them.
     */
    public function veteran(): static
    {
        return $this->state(function (array $attributes) {
            $wins = fake()->numberBetween(1, 40);
            $losses = fake()->numberBetween(1, 40);

            return [
                'rating' => fake()->numberBetween(800, 1400),
                'games_played' => $wins + $losses,
                'wins' => $wins,
                'losses' => $losses,
                'total_lines' => ($wins + $losses) * fake()->numberBetween(20, 60),
                'best_score' => fake()->numberBetween(20, 300) * 100,
            ];
        });
    }
}
