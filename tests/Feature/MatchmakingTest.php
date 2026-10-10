<?php

namespace Tests\Feature;

use App\Events\MatchFound;
use App\Game\Randomizer;
use App\Models\GameMatch;
use App\Models\QueueEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MatchmakingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([MatchFound::class]);
    }

    public function test_the_first_player_waits_in_the_queue(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/matchmaking')
            ->assertOk()
            ->assertJson(['status' => 'searching', 'players_waiting' => 1, 'rating_window' => 150]);

        $this->assertDatabaseHas('queue_entries', ['user_id' => $user->id]);
        Event::assertNotDispatched(MatchFound::class);
    }

    public function test_two_players_with_similar_ratings_are_paired_into_a_new_match(): void
    {
        $waiting = User::factory()->rated(1000)->create();
        $joining = User::factory()->rated(1100)->create();
        $this->actingAs($waiting)->postJson('/api/matchmaking');

        $response = $this->actingAs($joining)->postJson('/api/matchmaking');

        $match = GameMatch::sole();
        $response->assertOk()->assertJson(['status' => 'matched', 'match_id' => $match->id]);
        $this->assertDatabaseEmpty('queue_entries');
        $this->assertSame([$waiting->id, $joining->id], $match->players()->pluck('user_id')->all());
        $this->assertSame([1000, 1100], $match->players()->pluck('rating_before')->all());
        $this->assertTrue($match->starts_at->isFuture(), 'Matches start after a countdown.');

        $firstPiece = (new Randomizer($match->seed))->piece(0);
        $this->assertSame([$firstPiece, $firstPiece], $match->players()->pluck('current')->all());

        Event::assertDispatchedTimes(MatchFound::class, 2);
        Event::assertDispatched(MatchFound::class, fn (MatchFound $event) => $event->userId === $waiting->id && $event->matchId === $match->id);
        Event::assertDispatched(MatchFound::class, fn (MatchFound $event) => $event->userId === $joining->id && $event->matchId === $match->id);
    }

    public function test_players_too_far_apart_are_not_paired_until_the_window_widens(): void
    {
        $novice = User::factory()->rated(900)->create();
        $expert = User::factory()->rated(1300)->create();
        $this->actingAs($novice)->postJson('/api/matchmaking');

        $this->actingAs($expert)->postJson('/api/matchmaking')->assertJson(['status' => 'searching']);

        // 400 points apart: the window must reach 400, i.e. 150 + 25 * 10 seconds.
        $this->travel(9)->seconds();
        $this->actingAs($novice)->postJson('/api/matchmaking')->assertJson(['status' => 'searching']);

        $this->travel(1)->seconds();
        $this->actingAs($novice)->postJson('/api/matchmaking')->assertJson(['status' => 'matched']);
    }

    public function test_the_closest_rated_waiting_player_is_chosen_over_the_longest_waiting(): void
    {
        $longestWaiting = User::factory()->rated(1000)->create();
        $closest = User::factory()->rated(1200)->create(); // 200 apart: these two do not pair
        $this->actingAs($longestWaiting)->postJson('/api/matchmaking');
        $this->actingAs($closest)->postJson('/api/matchmaking');

        $newcomer = User::factory()->rated(1130)->create(); // 130 from one, 70 from the other
        $this->actingAs($newcomer)->postJson('/api/matchmaking')->assertJson(['status' => 'matched']);

        $this->assertEqualsCanonicalizing([$closest->id, $newcomer->id], GameMatch::sole()->players()->pluck('user_id')->all());
        $this->assertDatabaseHas('queue_entries', ['user_id' => $longestWaiting->id]);
    }

    public function test_players_who_stop_polling_are_dropped_from_the_queue(): void
    {
        $gone = User::factory()->create();
        $this->actingAs($gone)->postJson('/api/matchmaking');

        $this->travel(11)->seconds();
        $this->actingAs(User::factory()->create())->postJson('/api/matchmaking')->assertJson(['status' => 'searching']);

        $this->assertDatabaseMissing('queue_entries', ['user_id' => $gone->id]);
        $this->assertSame(0, GameMatch::count());
    }

    public function test_a_player_already_in_a_match_is_sent_back_to_it(): void
    {
        [$user, $opponent] = User::factory(2)->create();
        $match = GameMatch::factory()->between($user, $opponent)->create();

        $this->actingAs($user)->postJson('/api/matchmaking')->assertJson(['status' => 'matched', 'match_id' => $match->id]);

        $this->assertDatabaseEmpty('queue_entries');
    }

    public function test_a_player_can_leave_the_queue(): void
    {
        $user = User::factory()->create();
        QueueEntry::create(['user_id' => $user->id, 'rating' => 1000, 'joined_at' => now(), 'last_seen_at' => now()]);

        $this->actingAs($user)->deleteJson('/api/matchmaking')->assertNoContent();

        $this->assertDatabaseEmpty('queue_entries');
    }

    public function test_guests_cannot_queue(): void
    {
        $this->postJson('/api/matchmaking')->assertUnauthorized();
    }
}
