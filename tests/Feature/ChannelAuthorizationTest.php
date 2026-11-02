<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Qui peut s'abonner à quel canal temps réel ?
 */
class ChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurantA;

    private Restaurant $restaurantB;

    protected function setUp(): void
    {
        parent::setUp();

        // Un vrai pilote de diffusion est nécessaire pour que les canaux soient évalués
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'app_id' => 'test-app',
                'options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
            ],
        ]);

        $this->restaurantA = Restaurant::factory()->create();
        $this->restaurantB = Restaurant::factory()->create();
    }

    /** Un utilisateur du restaurant A demande à rejoindre un canal (du restaurant A ou B). */
    private function channelStatus(string $role, string $channel, bool $ownRestaurant = true): int
    {
        $user = User::factory()->create([
            'restaurant_id' => $this->restaurantA->id,
            'role' => $role,
        ]);
        $target = $ownRestaurant ? $this->restaurantA : $this->restaurantB;

        Sanctum::actingAs($user);

        return $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-{$channel}.{$target->id}",
        ])->getStatusCode();
    }

    public function test_dashboard_channel_is_reserved_to_admin_and_manager_of_the_restaurant(): void
    {
        $this->assertSame(200, $this->channelStatus('admin', 'dashboard'));
        $this->assertSame(200, $this->channelStatus('manager', 'dashboard'));
        $this->assertSame(403, $this->channelStatus('waiter', 'dashboard'));
        $this->assertSame(403, $this->channelStatus('kitchen', 'dashboard'));
        $this->assertSame(403, $this->channelStatus('admin', 'dashboard', ownRestaurant: false));
    }

    public function test_restaurant_channel_excludes_kitchen_and_other_restaurants(): void
    {
        $this->assertSame(200, $this->channelStatus('admin', 'restaurant'));
        $this->assertSame(200, $this->channelStatus('waiter', 'restaurant'));
        $this->assertSame(403, $this->channelStatus('kitchen', 'restaurant'));
        $this->assertSame(403, $this->channelStatus('admin', 'restaurant', ownRestaurant: false));
    }

    public function test_kitchen_channel_excludes_waiters_and_other_restaurants(): void
    {
        $this->assertSame(200, $this->channelStatus('admin', 'kitchen'));
        $this->assertSame(200, $this->channelStatus('kitchen', 'kitchen'));
        $this->assertSame(403, $this->channelStatus('waiter', 'kitchen'));
        $this->assertSame(403, $this->channelStatus('kitchen', 'kitchen', ownRestaurant: false));
    }
}
