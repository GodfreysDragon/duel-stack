<?php

namespace Database\Factories;

use App\Game\Board;
use App\Game\Randomizer;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchPlayer>
 */
class MatchPlayerFactory extends Factory
{
    /**
     * A player at the start of the match: empty board, first piece of the match's sequence.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_match_id' => GameMatch::factory(),
            'user_id' => User::factory(),
            'slot' => 0,
            'board' => Board::empty()->toString(),
            'current' => fn (array $attributes) => (new Randomizer(GameMatch::query()->whereKey($attributes['game_match_id'])->firstOrFail()->seed))->piece(0),
            'rating_before' => fn (array $attributes) => User::query()->whereKey($attributes['user_id'])->firstOrFail()->rating,
        ];
    }
}
