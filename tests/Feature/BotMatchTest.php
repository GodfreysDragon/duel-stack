<?php

namespace Tests\Feature;

use App\Events\MatchFound;
use App\Game\Randomizer;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\User;
use App\Services\BotDriver;
use App\Services\Matchmaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class BotMatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([MatchFound::class]);
        Sleep::fake(syncWithCarbon: true);
    }

    public function test_a_player_left_alone_in_the_queue_is_paired_with_the_closest_rated_bot(): void
    {
        $user = User::factory()->rated(1250)->create();
        $this->actingAs($user)->postJson('/api/matchmaking')->assertJson(['status' => 'searching', 'bot_in' => 15]);

        $this->travel(10)->seconds();
        $this->actingAs($user)->postJson('/api/matchmaking')->assertJson(['status' => 'searching', 'bot_in' => 5]);

        $this->travel(5)->seconds();
        $this->actingAs($user)->postJson('/api/matchmaking')->assertJson(['status' => 'matched']);

        $match = GameMatch::with('players.user')->sole();
        $this->assertSame([$user->name, 'Pro Bot'], $match->players->pluck('user.name')->all());
        $this->assertDatabaseEmpty('queue_entries');
        Event::assertDispatchedTimes(MatchFound::class, 1);
        Event::assertDispatched(MatchFound::class, fn (MatchFound $event) => $event->userId === $user->id);
    }

    public function test_bots_are_only_created_once_and_appear_as_bots(): void
    {
        $users = User::factory(2)->create();
        foreach ($users as $user) {
            // Polling as the client does, so the queue entry never goes stale.
            for ($waited = 0; $waited <= Matchmaker::BOT_AFTER_SECONDS; $waited += 5) {
                $response = $this->actingAs($user)->postJson('/api/matchmaking');
                $this->travel(5)->seconds();
            }
            $response->assertJson(['status' => 'matched']);
        }

        $this->assertSame(3, User::query()->whereNotNull('bot_level')->count());

        $match = GameMatch::query()->latest('id')->first();
        $this->actingAs($users[1])->getJson("/api/matches/{$match->id}")
            ->assertJsonPath('match.players.0.user.is_bot', false)
            ->assertJsonPath('match.players.1.user.is_bot', true);
    }

    public function test_the_bot_plays_its_pieces_at_its_levels_pace(): void
    {
        [$match, $bot] = $this->botMatch('medium');
        $match->players()->whereKeyNot($bot->id)->update(['last_seen_at' => now()->addSeconds(30)]);

        $this->artisan('bots:play', ['--seconds' => 10])->assertSuccessful();

        // 1.6 pieces a second, without a burst to catch up on the time before the driver started.
        $bot->refresh();
        $this->assertEqualsWithDelta(16, $bot->pieces, 1);
        $this->assertFalse($bot->topped_out);
        $this->assertSame($bot->pieces, $bot->moves()->count());
        $this->assertFalse($match->fresh()->isFinished());
    }

    public function test_a_failure_in_one_bot_match_does_not_stop_the_others(): void
    {
        [, $broken] = $this->botMatch('medium');
        [, $healthy] = $this->botMatch('hard');
        $broken->update(['board' => 'not a board']);
        $this->travel(1)->seconds();

        $this->assertSame(1, app(BotDriver::class)->tick());

        $this->assertSame(0, $broken->fresh()->pieces);
        $this->assertSame(1, $healthy->fresh()->pieces);
    }

    public function test_the_bot_raises_incoming_garbage(): void
    {
        [, $bot] = $this->botMatch('hard');
        $batch = $bot->incomingGarbage()->create(['lines' => 2, 'hole' => 4, 'created_at' => now()]);

        $this->artisan('bots:play', ['--seconds' => 3])->assertSuccessful();

        $this->assertNotNull($batch->fresh()->applied_at_piece);
        $this->assertSame(2, $bot->fresh()->garbage_received);
        $this->assertSame([['lines' => 2, 'hole' => 4]], $bot->moves()->whereNotNull('garbage')->sole()->garbage);
    }

    public function test_the_bot_claims_the_win_when_its_opponent_leaves(): void
    {
        [$match, $bot] = $this->botMatch('easy');

        $this->artisan('bots:play', ['--seconds' => 20])->assertSuccessful();

        $match->refresh();
        $this->assertTrue($match->isFinished());
        $this->assertSame('abandoned', $match->end_reason);
        $this->assertSame($bot->user_id, $match->winner_id);
    }

    public function test_a_bots_rating_changes_add_up_across_simultaneous_matches(): void
    {
        $bot = User::factory()->bot()->create();
        $users = User::factory(2)->create();
        $matches = $users->map(fn (User $user) => GameMatch::factory()->between($user, $bot)->create());

        foreach ($matches as $index => $match) {
            $this->actingAs($users[$index])->postJson("/api/matches/{$match->id}/forfeit")->assertOk();
        }

        $this->assertSame(1032, $bot->fresh()->rating);
        $this->assertSame(2, $bot->fresh()->wins);
    }

    public function test_bots_are_left_off_the_leaderboard(): void
    {
        User::factory()->bot()->create(['games_played' => 5, 'rating' => 2000]);
        $player = User::factory()->create(['games_played' => 5, 'rating' => 1100]);

        $this->getJson('/api/leaderboard')->assertJsonCount(1)->assertJsonPath('0.id', $player->id);
        $this->getJson("/api/players/{$player->name}")->assertJsonPath('rank', 1);
    }

    /**
     * A match that started 5 seconds ago between a player who never locks and a bot.
     *
     * @return array{0: GameMatch, 1: MatchPlayer}
     */
    private function botMatch(string $level): array
    {
        $match = GameMatch::factory()->between(User::factory()->create(), User::factory()->bot($level)->create())->create();
        $this->assertSame((new Randomizer($match->seed))->piece(0), $match->players[1]->current);

        return [$match, $match->players[1]];
    }
}
