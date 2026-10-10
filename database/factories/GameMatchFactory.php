<?php

namespace Database\Factories;

use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameMatch>
 */
class GameMatchFactory extends Factory
{
    /**
     * An active match whose countdown has already finished.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seed' => fake()->numberBetween(0, 4294967295),
            'status' => GameMatch::STATUS_ACTIVE,
            'starts_at' => now()->subSeconds(5),
        ];
    }

    public function seeded(int $seed): static
    {
        return $this->state(fn (array $attributes) => ['seed' => $seed]);
    }

    public function counting(): static
    {
        return $this->state(fn (array $attributes) => ['starts_at' => now()->addSeconds(GameMatch::COUNTDOWN_SECONDS)]);
    }

    public function finished(): static
    {
        return $this->state(fn (array $attributes) => ['status' => GameMatch::STATUS_FINISHED, 'ended_at' => now()]);
    }

    /**
     * Seats the two users in slots 0 and 1.
     */
    public function between(User $first, User $second): static
    {
        return $this->afterCreating(function (GameMatch $match) use ($first, $second) {
            foreach ([$first, $second] as $slot => $user) {
                MatchPlayer::factory()->for($match)->for($user)->create(['slot' => $slot]);
            }
        });
    }
}
