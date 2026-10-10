<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_session_has_no_user_but_gets_a_csrf_token(): void
    {
        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user', null)
            ->assertJsonStructure(['csrf_token']);
    }

    public function test_a_player_can_register_and_is_signed_in(): void
    {
        $response = $this->postJson('/api/register', ['name' => 'blockhead', 'password' => 'hunter22']);

        $response->assertCreated()
            ->assertJsonPath('user.name', 'blockhead')
            ->assertJsonPath('user.rating', 1000);
        $this->assertAuthenticatedAs(User::firstWhere('name', 'blockhead'));
    }

    public function test_anyone_can_play_as_a_guest_without_choosing_a_name(): void
    {
        $response = $this->postJson('/api/guest')->assertCreated();

        $this->assertMatchesRegularExpression('/^guest-[a-z0-9]{6}$/', $response->json('user.name'));
        $this->assertAuthenticatedAs(User::firstWhere('name', $response->json('user.name')));
        $this->assertNotNull($response->json('csrf_token'));
    }

    public function test_registration_rejects_taken_and_malformed_names(): void
    {
        User::factory()->create(['name' => 'taken']);

        $this->postJson('/api/register', ['name' => 'taken', 'password' => 'hunter22'])->assertJsonValidationErrors('name');
        $this->postJson('/api/register', ['name' => 'two words', 'password' => 'hunter22'])->assertJsonValidationErrors('name');
        $this->postJson('/api/register', ['name' => 'ok_name', 'password' => 'short'])->assertJsonValidationErrors('password');
    }

    public function test_a_player_can_log_in_with_the_right_password_only(): void
    {
        $user = User::factory()->create(['name' => 'stacker']);

        $this->postJson('/api/login', ['name' => 'stacker', 'password' => 'wrong-password'])->assertJsonValidationErrors('name');
        $this->assertGuest();

        $this->postJson('/api/login', ['name' => 'stacker', 'password' => 'password'])->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_me_reports_a_match_in_progress_so_a_reload_can_rejoin_it(): void
    {
        [$user, $opponent] = User::factory(2)->create();
        $match = GameMatch::factory()->between($user, $opponent)->create();

        $this->actingAs($user)->getJson('/api/me')->assertJsonPath('active_match_id', $match->id);
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/logout')->assertOk()->assertJsonPath('user', null);

        $this->assertGuest('web');
    }
}
