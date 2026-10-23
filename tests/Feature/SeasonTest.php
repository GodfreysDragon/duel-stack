<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeasonTest extends TestCase
{
    use RefreshDatabase;

    public function test_rolling_a_season_keeps_its_standings_and_soft_resets_ratings(): void
    {
        $season = Season::factory()->create(['number' => 3]);
        $top = User::factory()->create(['rating' => 1300]);
        $low = User::factory()->create(['rating' => 900]);
        $idle = User::factory()->create(['rating' => 1100]); // no games this season, still reset
        $bot = User::factory()->bot('medium')->create(['rating' => 1150]);
        GameMatch::factory()->finished()->between($top, $low)->create(['winner_id' => $top->id, 'ended_at' => now()->subMinute()]);

        $this->artisan('seasons:roll')->assertSuccessful();

        $this->assertNotNull($season->fresh()->ends_at);
        $this->assertSame([[$top->id, 1, 1300, 1], [$low->id, 2, 900, 0]], $season->standings()->orderBy('rank')->get()
            ->map(fn ($s) => [$s->user_id, $s->rank, $s->rating, $s->wins])->all());

        $this->assertSame([1150, 950, 1050], [$top->fresh()->rating, $low->fresh()->rating, $idle->fresh()->rating]);
        $this->assertSame(1000, $bot->fresh()->rating, 'bots go back to their base rating');

        $next = Season::current();
        $this->assertSame(4, $next->number);
        $this->getJson('/api/leaderboard')->assertJsonPath('season.number', 4)->assertJsonCount(0, 'players');
    }

    public function test_a_soft_reset_halves_the_distance_to_the_starting_rating(): void
    {
        $this->assertSame([1150, 850, 1000, 1001], array_map(Season::softReset(...), [1300, 700, 1000, 1003]));
    }

    public function test_inactive_players_ratings_decay_down_to_the_floor(): void
    {
        $inactive = now()->subDays(20);
        $high = User::factory()->create(['rating' => 1400, 'last_played_at' => $inactive]);
        $nearFloor = User::factory()->create(['rating' => 1205, 'last_played_at' => $inactive]);
        $belowFloor = User::factory()->create(['rating' => 1100, 'last_played_at' => $inactive]);
        $active = User::factory()->create(['rating' => 1400, 'last_played_at' => now()->subDays(5)]);
        $neverPlayed = User::factory()->create(['rating' => 1400]);
        $bot = User::factory()->bot('hard')->create(['rating' => 1400, 'last_played_at' => $inactive]);

        $this->artisan('ratings:decay')->assertSuccessful();

        $this->assertSame(
            [1390, 1200, 1100, 1400, 1400, 1400],
            collect([$high, $nearFloor, $belowFloor, $active, $neverPlayed, $bot])->map(fn (User $user) => $user->fresh()->rating)->all(),
        );
    }
}
