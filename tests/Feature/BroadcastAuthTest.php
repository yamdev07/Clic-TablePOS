<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Autorisation des canaux privés temps réel (POST /api/broadcasting/auth).
 * La signature Reverb/Pusher se calcule hors ligne : pas besoin de serveur Reverb.
 */
class BroadcastAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        // phpunit.xml utilise le broadcaster « null » : on réenregistre les canaux sur Reverb
        require base_path('routes/channels.php');
    }

    private function authChannel(User $user, string $channel)
    {
        $token = $user->createToken('t')->plainTextToken;

        return $this->postJson('/api/broadcasting/auth', [
            'socket_id'    => '1234.5678',
            'channel_name' => "private-{$channel}",
        ], ['Authorization' => "Bearer {$token}"]);
    }

    /** @test */
    public function kitchen_can_join_its_restaurant_kitchen_channel(): void
    {
        $r = Restaurant::factory()->create();
        $cook = User::factory()->create(['restaurant_id' => $r->id, 'role' => 'kitchen']);

        $this->authChannel($cook, "kitchen.{$r->id}")->assertOk()->assertJsonStructure(['auth']);
    }

    /** @test */
    public function waiter_and_cashier_can_join_restaurant_channel(): void
    {
        $r = Restaurant::factory()->create();

        foreach (['waiter', 'cashier'] as $role) {
            $user = User::factory()->create(['restaurant_id' => $r->id, 'role' => $role]);
            $this->app['auth']->forgetGuards();
            $this->authChannel($user, "restaurant.{$r->id}")->assertOk();
        }
    }

    /** @test */
    public function user_cannot_join_another_restaurants_channel(): void
    {
        $mine = Restaurant::factory()->create();
        $other = Restaurant::factory()->create();
        $cook = User::factory()->create(['restaurant_id' => $mine->id, 'role' => 'kitchen']);

        $this->authChannel($cook, "kitchen.{$other->id}")->assertForbidden();
    }

    /** @test */
    public function guest_cannot_authenticate_channels(): void
    {
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-kitchen.x'])
            ->assertUnauthorized();
    }
}
