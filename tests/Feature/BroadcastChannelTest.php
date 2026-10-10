<?php

namespace Tests\Feature;

use App\Events\BoardUpdated;
use App\Events\GarbageSent;
use App\Events\MatchEnded;
use App\Events\MatchFound;
use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Channel authorisation through the real /broadcasting/auth endpoint, signed
 * by the Reverb (Pusher protocol) broadcaster, and the event channel names.
 */
class BroadcastChannelTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private GameMatch $match;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        // Channels were registered on the null broadcaster at boot; register them on Reverb too.
        require base_path('routes/channels.php');

        [$this->alice, $this->bob] = User::factory(2)->create();
        $this->match = GameMatch::factory()->between($this->alice, $this->bob)->create();
    }

    public function test_players_can_join_their_match_presence_channel_with_public_info(): void
    {
        $response = $this->actingAs($this->alice)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "presence-match.{$this->match->id}",
        ]);

        $response->assertOk();
        $channelData = json_decode($response->json('channel_data'), true);
        $this->assertSame((string) $this->alice->id, $channelData['user_id']); // Pusher protocol: a string
        $this->assertSame(['id' => $this->alice->id, 'name' => $this->alice->name, 'rating' => 1000], $channelData['user_info']);

        $expectedSignature = hash_hmac('sha256', "1234.5678:presence-match.{$this->match->id}:".$response->json('channel_data'), 'test-secret');
        $this->assertSame("test-key:{$expectedSignature}", $response->json('auth'));
    }

    public function test_outsiders_cannot_join_a_match_channel(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "presence-match.{$this->match->id}",
        ])->assertForbidden();
    }

    public function test_a_user_channel_is_private_to_its_owner(): void
    {
        $this->actingAs($this->alice)->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => "private-user.{$this->alice->id}"])->assertOk();
        $this->actingAs($this->alice)->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => "private-user.{$this->bob->id}"])->assertForbidden();
    }

    public function test_events_broadcast_on_the_expected_channels_with_stable_names(): void
    {
        $player = $this->match->players()->firstOrFail();
        $batch = $player->incomingGarbage()->create(['lines' => 2, 'hole' => 5, 'created_at' => now()]);

        $this->assertSame("private-user.{$this->alice->id}", (string) (new MatchFound($this->alice->id, $this->match->id))->broadcastOn()[0]);
        $this->assertSame("presence-match.{$this->match->id}", (string) (new BoardUpdated($player))->broadcastOn()[0]);
        $this->assertSame("presence-match.{$this->match->id}", (string) (new GarbageSent($this->match, $batch, $this->bob->id))->broadcastOn()[0]);
        $this->assertSame("presence-match.{$this->match->id}", (string) (new MatchEnded($this->match))->broadcastOn()[0]);

        $this->assertSame(
            ['from_user_id' => $this->bob->id, 'batch' => ['id' => $batch->id, 'lines' => 2, 'hole' => 5]],
            (new GarbageSent($this->match, $batch, $this->bob->id))->broadcastWith(),
        );
    }
}
