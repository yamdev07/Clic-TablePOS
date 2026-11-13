<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StatsCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Table $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->table = Table::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'number' => '1',
            'capacity' => 4,
            'status' => 'occupied',
            'qr_code' => 'https://example.com/'.Str::random(8),
        ]);
        MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'price' => 5000,
        ]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => $role,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    // ------------------------------------------------------------------
    // 1. Créer une commande invalide le cache
    // ------------------------------------------------------------------

    public function test_creating_an_order_invalidates_the_cache(): void
    {
        $this->actingAsRole('waiter');

        // Charger les stats → activeOrders = 0, todayOrders = 0
        $data1 = $this->getJson('/api/stats')->assertOk()->json();
        $this->assertSame(0, $data1['activeOrders']);

        // Créer une commande
        $this->postJson('/api/orders', [
            'table_id' => $this->table->id,
            'type' => 'dine_in',
        ])->assertCreated();

        // Recharger → doit refléter la nouvelle commande
        $data2 = $this->getJson('/api/stats')->assertOk()->json();
        $this->assertSame(1, $data2['todayOrders']);
    }

    // ------------------------------------------------------------------
    // 2. Envoyer en cuisine invalide le cache
    // ------------------------------------------------------------------

    public function test_sending_to_kitchen_invalidates_the_cache(): void
    {
        $this->actingAsRole('waiter');

        // Créer une commande (open)
        $orderId = $this->postJson('/api/orders', [
            'table_id' => $this->table->id,
            'type' => 'dine_in',
        ])->assertCreated()->json('data.id');

        // Charger les stats → activeOrders = 0 (open n'est pas actif)
        $data1 = $this->getJson('/api/stats')->assertOk()->json();
        $this->assertSame(0, $data1['activeOrders']);

        // Envoyer en cuisine → la commande devient active
        $this->postJson("/api/orders/{$orderId}/send-to-kitchen")->assertOk();

        // Recharger → activeOrders = 1
        $data2 = $this->getJson('/api/stats')->assertOk()->json();
        $this->assertSame(1, $data2['activeOrders']);
    }

    // ------------------------------------------------------------------
    // 3. Annuler une commande invalide le cache
    // ------------------------------------------------------------------

    public function test_cancelling_an_order_invalidates_the_cache(): void
    {
        $this->actingAsRole('waiter');

        // Créer + envoyer en cuisine
        $orderId = $this->postJson('/api/orders', [
            'table_id' => $this->table->id,
            'type' => 'dine_in',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/orders/{$orderId}/send-to-kitchen")->assertOk();

        // Charger → activeOrders = 1
        $data1 = $this->getJson('/api/stats')->assertOk()->json();
        $this->assertSame(1, $data1['activeOrders']);

        // Annuler (in_progress sans plat lancé → OK)
        $this->postJson("/api/orders/{$orderId}/cancel")->assertOk();

        // Recharger → activeOrders = 0
        $data2 = $this->getJson('/api/stats')->assertOk()->json();
        $this->assertSame(0, $data2['activeOrders']);
    }
}
