<?php

namespace Tests\Feature;

use App\Http\Controllers\PlayerController;
use App\Models\GameMatch;
use App\Models\Season;
use App\Models\SeasonStanding;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A finished match between two players, with their scores.
     */
    private function finished(User $winner, User $loser, int $winnerScore, int $loserScore, ?CarbonImmutable $endedAt = null): GameMatch
    {
        $match = GameMatch::factory()->finished()->between($winner, $loser)->create(['winner_id' => $winner->id, 'ended_at' => $endedAt ?? now()]);
        $match->players()->whereBelongsTo($winner)->update(['score' => $winnerScore]);
        $match->players()->whereBelongsTo($loser)->update(['score' => $loserScore]);

        return $match;
    }

    public function test_the_leaderboard_ranks_this_seasons_players_by_rating_or_best_score(): void
    {
        $season = Season::factory()->create();
        $first = User::factory()->create(['name' => 'first', 'rating' => 1250]);
        $second = User::factory()->create(['name' => 'second', 'rating' => 1100]);
        User::factory()->create(['name' => 'newbie']); // no games this season
        $this->finished($first, $second, 4000, 9000);
        $this->finished($first, $second, 3000, 100);

        $this->getJson('/api/leaderboard')
            ->assertOk()
            ->assertJsonPath('season.number', $season->number)
            ->assertJsonPath('season.ends_at', null)
            ->assertJsonPath('players.*.user.name', ['first', 'second'])
            ->assertJsonPath('players.0.rating', 1250)
            ->assertJsonPath('players.0.games_played', 2)
            ->assertJsonPath('players.0.wins', 2)
            ->assertJsonPath('players.1.wins', 0)
            ->assertJsonPath('players.1.best_score', 9000);

        $this->getJson('/api/leaderboard?sort=best_score')->assertJsonPath('players.*.user.name', ['second', 'first']);
    }

    public function test_matches_from_before_the_season_do_not_count(): void
    {
        Season::factory()->create();
        $this->finished(User::factory()->create(), User::factory()->create(), 100, 100, now()->subMonth()->toImmutable());

        $this->getJson('/api/leaderboard')->assertOk()->assertJsonCount(0, 'players');
    }

    public function test_a_past_season_shows_its_final_standings(): void
    {
        $past = Season::factory()->ended()->create(['number' => 10]);
        Season::factory()->create(['number' => 11]);
        $standing = SeasonStanding::factory()->for($past)->create(['rank' => 1, 'rating' => 1420, 'wins' => 9]);

        $this->getJson('/api/leaderboard?season=10')
            ->assertOk()
            ->assertJsonPath('season.number', 10)
            ->assertJsonPath('seasons.*.number', [11, 10, 1])
            ->assertJsonPath('players.0.user.name', $standing->user->name)
            ->assertJsonPath('players.0.rating', 1420)
            ->assertJsonPath('players.0.wins', 9);
    }

    public function test_the_sort_key_and_season_are_validated(): void
    {
        $this->getJson('/api/leaderboard?sort=password&season=99')->assertJsonValidationErrors(['sort', 'season']);
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
