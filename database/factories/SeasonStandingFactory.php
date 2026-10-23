<?php

namespace Database\Factories;

use App\Models\Season;
use App\Models\SeasonStanding;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeasonStanding>
 */
class SeasonStandingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_id' => Season::factory()->ended(),
            'user_id' => User::factory(),
            'rank' => 1,
            'rating' => 1000,
            'games_played' => 10,
            'wins' => 5,
            'best_score' => 5000,
        ];
    }
}
