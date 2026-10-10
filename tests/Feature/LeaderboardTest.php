<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_leaderboard_ranks_players_who_have_played_by_rating(): void
    {
        User::factory()->create(['name' => 'newbie']); // no games yet
        User::factory()->create(['name' => 'second', 'rating' => 1100, 'games_played' => 3, 'best_score' => 9000]);
        User::factory()->create(['name' => 'first', 'rating' => 1250, 'games_played' => 5, 'best_score' => 4000]);
        User::factory()->create(['name' => 'third', 'rating' => 1100, 'games_played' => 1, 'best_score' => 100]);

        $this->getJson('/api/leaderboard')
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('*.name', ['first', 'second', 'third']);

        $this->getJson('/api/leaderboard?sort=best_score')->assertJsonPath('*.name', ['second', 'first', 'third']);
    }

    public function test_the_sort_key_is_validated(): void
    {
        $this->getJson('/api/leaderboard?sort=password')->assertJsonValidationErrors('sort');
    }

    public function test_a_profile_shows_stats_rank_and_recent_finished_matches(): void
    {
        $user = User::factory()->create(['name' => 'zed', 'rating' => 1200, 'games_played' => 2, 'wins' => 2]);
        $opponent = User::factory()->create(['rating' => 1300, 'games_played' => 2]);
        $finished = GameMatch::factory()->finished()->between($user, $opponent)->create(['winner_id' => $user->id]);
        GameMatch::factory()->between($user, $opponent)->create(); // still live: not listed

        $this->getJson('/api/players/zed')
            ->assertOk()
            ->assertJsonPath('user.wins', 2)
            ->assertJsonPath('rank', 2)
            ->assertJsonCount(1, 'recent_matches')
            ->assertJsonPath('recent_matches.0.id', $finished->id)
            ->assertJsonPath('recent_matches.0.winner_id', $user->id);
    }

    public function test_an_unknown_player_is_not_found(): void
    {
        $this->getJson('/api/players/nobody')->assertNotFound();
    }
}
