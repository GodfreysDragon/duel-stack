<?php

namespace Tests\Feature;

use App\Http\Controllers\PlayerController;
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

    public function test_a_profile_shows_stats_and_rank(): void
    {
        User::factory()->create(['name' => 'zed', 'rating' => 1200, 'games_played' => 2, 'wins' => 2]);
        User::factory()->create(['rating' => 1300, 'games_played' => 2]);

        $this->getJson('/api/players/zed')
            ->assertOk()
            ->assertJsonPath('user.wins', 2)
            ->assertJsonPath('rank', 2)
            ->assertJsonPath('live_match_id', null);
    }

    public function test_a_profile_links_to_the_match_the_player_is_in(): void
    {
        $user = User::factory()->create(['name' => 'zed']);
        $live = GameMatch::factory()->between($user, User::factory()->create())->create();

        $this->getJson('/api/players/zed')->assertOk()->assertJsonPath('live_match_id', $live->id);
    }

    public function test_a_players_finished_matches_are_listed_newest_first_a_page_at_a_time(): void
    {
        $user = User::factory()->create(['name' => 'zed']);
        $opponent = User::factory()->create();
        $finished = GameMatch::factory(PlayerController::MATCHES_PER_PAGE + 2)->finished()->between($user, $opponent)->create();
        GameMatch::factory()->between($user, $opponent)->create(); // still live: not listed
        GameMatch::factory()->finished()->between($opponent, User::factory()->create())->create(); // someone else's

        $this->getJson('/api/players/zed/matches')
            ->assertOk()
            ->assertJsonCount(PlayerController::MATCHES_PER_PAGE, 'data')
            ->assertJsonPath('data.0.id', $finished->last()->id)
            ->assertJsonPath('meta.total', PlayerController::MATCHES_PER_PAGE + 2)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/players/zed/matches?page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.id', $finished->first()->id);
    }

    public function test_an_unknown_player_is_not_found(): void
    {
        $this->getJson('/api/players/nobody')->assertNotFound();
    }
}
