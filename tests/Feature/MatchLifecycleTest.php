<?php

namespace Tests\Feature;

use App\Events\MatchEnded;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MatchLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private GameMatch $match;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([MatchEnded::class]);

        [$this->alice, $this->bob] = User::factory(2)->create();
        $this->match = GameMatch::factory()->between($this->alice, $this->bob)->create();
    }

    public function test_a_player_can_load_the_match_with_their_own_state_and_pending_garbage(): void
    {
        $player = $this->match->players()->whereBelongsTo($this->alice)->sole();
        $batch = $player->incomingGarbage()->create(['lines' => 3, 'hole' => 2, 'created_at' => now()]);

        $this->actingAs($this->alice)->getJson("/api/matches/{$this->match->id}")
            ->assertOk()
            ->assertJsonPath('match.seed', $this->match->seed)
            ->assertJsonPath('match.players.0.user.name', $this->alice->name)
            ->assertJsonPath('match.players.1.user.name', $this->bob->name)
            ->assertJsonPath('you.state.current', $player->current)
            ->assertJsonPath('you.pending_garbage', [['id' => $batch->id, 'lines' => 3, 'hole' => 2]])
            ->assertJsonStructure(['server_time']);
    }

    public function test_a_live_match_is_private_to_its_players_but_a_finished_one_is_public(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->getJson("/api/matches/{$this->match->id}")->assertForbidden();

        $this->match->update(['status' => GameMatch::STATUS_FINISHED]);
        $this->actingAs($outsider)->getJson("/api/matches/{$this->match->id}")->assertOk()->assertJsonPath('you', null);
    }

    public function test_forfeiting_hands_the_win_to_the_opponent(): void
    {
        $this->actingAs($this->alice)->postJson("/api/matches/{$this->match->id}/forfeit")
            ->assertOk()
            ->assertJsonPath('match.winner_id', $this->bob->id)
            ->assertJsonPath('match.end_reason', 'forfeit');

        $this->assertDatabaseHas('users', ['id' => $this->bob->id, 'wins' => 1, 'rating' => 1016]);
        Event::assertDispatched(MatchEnded::class);

        $this->actingAs($this->bob)->postJson("/api/matches/{$this->match->id}/forfeit")->assertConflict();
    }

    public function test_a_win_can_be_claimed_only_once_the_opponent_has_gone_quiet(): void
    {
        $this->match->players()->update(['last_active_at' => now()]);

        $this->travel(10)->seconds();
        $this->actingAs($this->alice)->postJson("/api/matches/{$this->match->id}/claim")
            ->assertConflict()
            ->assertJsonPath('message', 'Your opponent is still playing.');

        $this->travel(11)->seconds();
        $this->actingAs($this->alice)->postJson("/api/matches/{$this->match->id}/claim")
            ->assertOk()
            ->assertJsonPath('match.winner_id', $this->alice->id)
            ->assertJsonPath('match.end_reason', 'abandoned');
    }

    public function test_outsiders_cannot_forfeit_or_claim(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->postJson("/api/matches/{$this->match->id}/forfeit")->assertForbidden();
        $this->actingAs($outsider)->postJson("/api/matches/{$this->match->id}/claim")->assertForbidden();
    }

    public function test_a_replay_lists_every_move_once_the_match_is_over(): void
    {
        $player = $this->match->players()->whereBelongsTo($this->alice)->sole();
        $player->moves()->create([
            'piece_number' => 0, 'piece' => 'T', 'rotation' => 2, 'x' => 4, 'y' => 19, 'held' => false,
            'garbage' => [['lines' => 1, 'hole' => 3]], 'cleared' => 0, 'attack' => 0, 'at_ms' => 812,
        ]);

        $this->actingAs($this->alice)->getJson("/api/matches/{$this->match->id}/replay")->assertForbidden();

        $this->match->update(['status' => GameMatch::STATUS_FINISHED]);
        $this->actingAs(User::factory()->create())->getJson("/api/matches/{$this->match->id}/replay")
            ->assertOk()
            ->assertJsonPath("moves.{$this->alice->id}.0.piece", 'T')
            ->assertJsonPath("moves.{$this->alice->id}.0.garbage", [['lines' => 1, 'hole' => 3]])
            ->assertJsonPath("moves.{$this->alice->id}.0.at_ms", 812)
            ->assertJsonPath("moves.{$this->bob->id}", []);
    }

    public function test_matches_abandoned_by_both_players_expire_without_rating_changes(): void
    {
        $lively = GameMatch::factory()->between(...User::factory(2)->create())->create();
        $lively->players()->update(['last_active_at' => now()->addMinutes(2)]);

        $this->travel(3)->minutes();
        $this->artisan('matches:expire-idle')->expectsOutput('Expired 1 idle match(es).')->assertSuccessful();

        $match = $this->match->fresh();
        $this->assertSame('no_contest', $match->end_reason);
        $this->assertNull($match->winner_id);
        $this->assertSame([1000, 1000], $match->players()->pluck('rating_after')->all());
        $this->assertDatabaseHas('users', ['id' => $this->alice->id, 'rating' => 1000, 'games_played' => 0]);
        $this->assertFalse($lively->fresh()->isFinished());
    }

    public function test_finished_matches_reject_further_play(): void
    {
        $this->match->update(['status' => GameMatch::STATUS_FINISHED]);

        $this->actingAs($this->alice)->postJson("/api/matches/{$this->match->id}/claim")->assertConflict();
        $this->assertSame(0, MatchPlayer::whereNotNull('rating_after')->count());
    }
}
