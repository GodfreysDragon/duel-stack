<?php

namespace Tests\Feature;

use App\Events\BoardUpdated;
use App\Events\GarbageSent;
use App\Events\MatchEnded;
use App\Models\GameMatch;
use App\Models\GarbageBatch;
use App\Models\MatchPlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LockSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private GameMatch $match;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([BoardUpdated::class, GarbageSent::class, MatchEnded::class]);

        [$this->alice, $this->bob] = User::factory(2)->create();
        // Seed 0 deals I, O, T, L, ...
        $this->match = GameMatch::factory()->seeded(0)->between($this->alice, $this->bob)->create();
    }

    private function player(User $user): MatchPlayer
    {
        return $this->match->players()->whereBelongsTo($user)->sole();
    }

    /**
     * Gives a player a prepared board (bottom rows, top row first) and current piece.
     *
     * @param  list<string>  $rows
     * @param  array<string, mixed>  $attributes
     */
    private function arrange(User $user, array $rows, string $current, array $attributes = []): void
    {
        $board = str_repeat('.', 10 * (22 - count($rows))).implode('', $rows);
        $this->player($user)->update(['board' => $board, 'current' => $current, ...$attributes]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function lock(array $overrides = []): array
    {
        return [...['piece_number' => 0, 'piece' => 'I', 'rotation' => 0, 'x' => 3, 'y' => 20, 'held' => false, 'garbage_ids' => []], ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $lock
     */
    private function submit(User $user, array $lock): TestResponse
    {
        return $this->actingAs($user)->postJson("/api/matches/{$this->match->id}/locks", $lock);
    }

    public function test_a_legal_lock_advances_the_authoritative_state_and_is_shown_to_the_opponent(): void
    {
        $response = $this->submit($this->alice, $this->lock());

        $response->assertOk()
            ->assertJsonPath('state.pieces', 1)
            ->assertJsonPath('state.current', 'O')
            ->assertJsonPath('state.board', str_repeat('.', 210).'...IIII...')
            ->assertJsonPath('attack', 0)
            ->assertJsonPath('sent', null)
            ->assertJsonPath('match_over', false);

        $this->assertDatabaseHas('match_moves', ['match_player_id' => $this->player($this->alice)->id, 'piece_number' => 0, 'piece' => 'I', 'x' => 3, 'y' => 20]);
        Event::assertDispatched(BoardUpdated::class, fn (BoardUpdated $event) => $event->player->user_id === $this->alice->id
            && $event->broadcastWith()['board'] === str_repeat('.', 210).'...IIII...'
            && $event->broadcastWith()['lock'] === ['piece' => 'I', 'rotation' => 0, 'x' => 3, 'y' => 20]);
    }

    public function test_an_illegal_lock_is_rejected_with_the_state_to_resync_from(): void
    {
        $this->submit($this->alice, $this->lock(['y' => 12]))
            ->assertConflict()
            ->assertJsonPath('message', 'Piece is floating.')
            ->assertJsonPath('state.pieces', 0)
            ->assertJsonPath('state.current', 'I')
            ->assertJsonPath('pending_garbage', []);

        $this->assertDatabaseEmpty('match_moves');
        Event::assertNotDispatched(BoardUpdated::class);
    }

    public function test_a_lock_out_of_sequence_is_rejected(): void
    {
        $this->submit($this->alice, $this->lock(['piece_number' => 3]))
            ->assertConflict()
            ->assertJsonPath('message', 'Expected piece #0, got #3.');
    }

    public function test_locks_are_refused_during_the_countdown(): void
    {
        $this->match->update(['starts_at' => now()->addSeconds(3)]);

        $this->submit($this->alice, $this->lock())->assertConflict()->assertJsonPath('message', 'The match has not started yet.');
    }

    public function test_only_the_two_players_can_submit_locks(): void
    {
        $this->submit(User::factory()->create(), $this->lock())->assertForbidden();
    }

    public function test_malformed_locks_fail_validation(): void
    {
        $this->submit($this->alice, $this->lock(['piece' => 'X', 'rotation' => 7, 'garbage_ids' => null]))
            ->assertJsonValidationErrors(['piece', 'rotation', 'garbage_ids']);
    }

    public function test_clearing_lines_sends_garbage_to_the_opponent(): void
    {
        $this->arrange($this->alice, ['G.........', 'GGGGGGGG..', 'GGGGGGGG..'], 'O');

        $response = $this->submit($this->alice, $this->lock(['piece' => 'O', 'x' => 7, 'y' => 20]));

        $response->assertOk()->assertJsonPath('cleared', [20, 21])->assertJsonPath('attack', 1)->assertJsonPath('sent.lines', 1);
        $batch = GarbageBatch::sole();
        $this->assertSame($this->player($this->bob)->id, $batch->target_player_id);
        $this->assertSame(1, $this->player($this->alice)->garbage_sent);
        Event::assertDispatched(GarbageSent::class, fn (GarbageSent $event) => $event->batch->is($batch) && $event->fromUserId === $this->alice->id);
    }

    public function test_incoming_garbage_rises_when_the_client_applies_it(): void
    {
        $batch = $this->player($this->bob)->incomingGarbage()->create(['lines' => 2, 'hole' => 4, 'created_at' => now()]);

        $this->submit($this->bob, $this->lock(['garbage_ids' => [$batch->id]]))
            ->assertOk()
            ->assertJsonPath('state.board', str_repeat('.', 190).'...IIII...GGGG.GGGGGGGGG.GGGGG');

        $this->assertSame(0, $batch->fresh()->applied_at_piece);
        $this->assertSame(2, $this->player($this->bob)->garbage_received);
    }

    public function test_garbage_must_be_applied_oldest_first(): void
    {
        $bob = $this->player($this->bob);
        $bob->incomingGarbage()->create(['lines' => 1, 'hole' => 0, 'created_at' => now()]);
        $newer = $bob->incomingGarbage()->create(['lines' => 1, 'hole' => 1, 'created_at' => now()]);

        $this->submit($this->bob, $this->lock(['garbage_ids' => [$newer->id]]))
            ->assertConflict()
            ->assertJsonPath('message', 'Garbage must rise oldest first and only once.')
            ->assertJsonCount(2, 'pending_garbage');
    }

    public function test_garbage_cannot_be_dodged_once_the_grace_period_is_over(): void
    {
        $this->player($this->bob)->incomingGarbage()->create(['lines' => 1, 'hole' => 0, 'created_at' => now()]);

        // Still in flight to the client: it may lock without it.
        $this->submit($this->bob, $this->lock())->assertOk();

        $this->travel(4)->seconds();
        $this->submit($this->bob, $this->lock(['piece_number' => 1, 'piece' => 'O', 'x' => 6, 'y' => 20]))
            ->assertConflict()
            ->assertJsonPath('message', 'Incoming garbage was not applied.');
    }

    public function test_topping_out_ends_the_match_and_updates_ratings_and_stats(): void
    {
        $this->arrange($this->alice, array_fill(0, 18, 'GGGGGGGGG.'), 'I', ['score' => 900, 'lines' => 7]);
        $this->arrange($this->bob, [], 'I', ['score' => 1500, 'lines' => 12]);

        // A vertical I in column 4 blocks the spawn area.
        $this->submit($this->alice, $this->lock(['rotation' => 1, 'x' => 2, 'y' => 0]))
            ->assertOk()
            ->assertJsonPath('match_over', true)
            ->assertJsonPath('state.toppedOut', true);

        $match = $this->match->fresh();
        $this->assertTrue($match->isFinished());
        $this->assertSame($this->bob->id, $match->winner_id);
        $this->assertSame('topout', $match->end_reason);

        $this->assertSame(1016, $this->player($this->bob)->rating_after);
        $this->assertSame(984, $this->player($this->alice)->rating_after);
        $this->assertDatabaseHas('users', ['id' => $this->bob->id, 'rating' => 1016, 'games_played' => 1, 'wins' => 1, 'losses' => 0, 'total_lines' => 12, 'best_score' => 1500]);
        $this->assertDatabaseHas('users', ['id' => $this->alice->id, 'rating' => 984, 'games_played' => 1, 'wins' => 0, 'losses' => 1, 'total_lines' => 7, 'best_score' => 900]);

        Event::assertDispatched(MatchEnded::class, fn (MatchEnded $event) => $event->match->id === $this->match->id
            && $event->broadcastWith()['match']['winner_id'] === $this->bob->id);

        $this->submit($this->bob, $this->lock())->assertConflict()->assertJsonPath('message', 'The match is over.');
    }
}
