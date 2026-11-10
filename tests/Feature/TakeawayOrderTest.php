<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TakeawayOrderTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Table $table;

    private MenuItem $dish;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->table = Table::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => 'occupied',
            'number' => '1',
        ]);
        $this->dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'price' => 1000,
        ]);
    }

    private function actingAsRole(string $role, ?Restaurant $restaurant = null): User
    {
        $user = User::factory()->create([
            'restaurant_id' => ($restaurant ?? $this->restaurant)->id,
            'role' => $role,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function addDish(Order $order): void
    {
        OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => $this->dish->id,
            'item_name' => $this->dish->name,
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
            'kitchen_status' => 'pending',
        ]);
        $order->recalculate();
    }

    // ------------------------------------------------------------------
    // 1. Création sans table = takeaway automatique
    // ------------------------------------------------------------------

    public function test_a_takeaway_order_can_be_created_without_a_table(): void
    {
        $this->actingAsRole('waiter');

        $response = $this->postJson('/api/orders', [])->assertCreated();

        $response->assertJsonPath('data.type', 'takeaway');
        $response->assertJsonPath('data.table_id', null);

        $this->assertDatabaseHas('orders', [
            'restaurant_id' => $this->restaurant->id,
            'type' => 'takeaway',
            'table_id' => null,
            'status' => 'open',
        ]);
    }

    // ------------------------------------------------------------------
    // 2. type=takeaway explicite
    // ------------------------------------------------------------------

    public function test_a_takeaway_order_can_be_created_with_type_explicit(): void
    {
        $this->actingAsRole('waiter');

        $response = $this->postJson('/api/orders', ['type' => 'takeaway'])->assertCreated();
        $response->assertJsonPath('data.type', 'takeaway');
        $response->assertJsonPath('data.table_id', null);
    }

    // ------------------------------------------------------------------
    // 3. Table = dine_in (comportement actuel)
    // ------------------------------------------------------------------

    public function test_a_dine_in_order_still_works(): void
    {
        $this->actingAsRole('waiter');

        $response = $this->postJson('/api/orders', [
            'table_id' => $this->table->id,
            'guest_count' => 4,
        ])->assertCreated();

        $response->assertJsonPath('data.type', 'dine_in');
        $response->assertJsonPath('data.table_id', $this->table->id);
        $response->assertJsonPath('data.guest_count', 4);

        $this->assertDatabaseHas('orders', [
            'table_id' => $this->table->id,
            'type' => 'dine_in',
            'guest_count' => 4,
        ]);
    }

    // ------------------------------------------------------------------
    // 4. type=dine_in sans table → 422
    // ------------------------------------------------------------------

    public function test_a_dine_in_order_requires_a_table(): void
    {
        $this->actingAsRole('waiter');

        $this->postJson('/api/orders', ['type' => 'dine_in'])
            ->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    // ------------------------------------------------------------------
    // 5. type=takeaway + table → 422
    // ------------------------------------------------------------------

    public function test_a_takeaway_order_cannot_have_a_table(): void
    {
        $this->actingAsRole('waiter');

        $this->postJson('/api/orders', [
            'type' => 'takeaway',
            'table_id' => $this->table->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    // ------------------------------------------------------------------
    // 6. delivery refusé
    // ------------------------------------------------------------------

    public function test_delivery_type_is_refused(): void
    {
        $this->actingAsRole('waiter');

        $this->postJson('/api/orders', ['type' => 'delivery'])
            ->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    // ------------------------------------------------------------------
    // 7. Takeaway n'occupe pas de table
    // ------------------------------------------------------------------

    public function test_a_takeaway_order_does_not_occupy_a_table(): void
    {
        $this->actingAsRole('waiter');

        $beforeCurrent = $this->table->fresh()->current_order_id;
        $beforeStatus = $this->table->fresh()->status;

        $this->postJson('/api/orders', [])->assertCreated();

        $this->assertSame($beforeCurrent, $this->table->fresh()->current_order_id);
        $this->assertSame($beforeStatus, $this->table->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 8. Cycle de vie complet
    // ------------------------------------------------------------------

    public function test_a_takeaway_order_goes_through_the_normal_lifecycle(): void
    {
        $this->actingAsRole('waiter');

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => null,
            'type' => 'takeaway',
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => 'open',
        ]);
        $this->addDish($order);

        // Envoi en cuisine
        $this->postJson("/api/orders/{$order->id}/send-to-kitchen")->assertOk();
        $this->assertSame('in_progress', $order->fresh()->status);

        // Kitchen : lancer
        $kitchen = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'kitchen',
        ]);
        Sanctum::actingAs($kitchen);
        $this->patchJson("/api/kitchen/orders/{$order->id}/cooking")->assertOk();

        // Kitchen : prête
        $this->patchJson("/api/kitchen/orders/{$order->id}/ready")->assertOk();
        $this->assertSame('ready', $order->fresh()->status);

        // Serveur : servie (remise au client)
        Sanctum::actingAs(User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'waiter',
        ]));
        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'served'])->assertOk();
        $this->assertSame('served', $order->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 9. Takeaway peut être payée
    // ------------------------------------------------------------------

    public function test_a_takeaway_order_can_be_paid(): void
    {
        $this->actingAsRole('waiter');

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => null,
            'type' => 'takeaway',
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => 'served',
        ]);
        $this->addDish($order);

        $this->actingAsRole('cashier');
        $this->postJson("/api/orders/{$order->id}/payments", [
            'amount' => $order->fresh()->due_amount,
            'method' => 'cash',
            'receipt_confirmed' => true,
        ])->assertCreated();

        $this->assertSame('paid', $order->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 10. Isolation
    // ------------------------------------------------------------------

    public function test_takeaway_is_isolated_across_restaurants(): void
    {
        $this->actingAsRole('waiter');
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => null,
            'type' => 'takeaway',
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => 'open',
        ]);

        $other = Restaurant::factory()->create();
        $this->actingAsRole('waiter', $other);
        $this->getJson("/api/orders/{$order->id}")->assertNotFound();
    }
}
