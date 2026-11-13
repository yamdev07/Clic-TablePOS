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

class KitchenEstimatedPrepTimeTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Table $table;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->admin = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'admin',
        ]);
        $this->table = Table::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'number' => '1',
            'capacity' => 4,
            'status' => 'occupied',
            'qr_code' => 'https://example.com/'.Str::random(8),
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

    private function makeInProgressOrder(array $items): Order
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'type' => 'dine_in',
            'user_id' => $this->admin->id,
            'status' => 'in_progress',
        ]);

        foreach ($items as $entry) {
            OrderItem::create([
                'id' => (string) Str::uuid(),
                'order_id' => $order->id,
                'menu_item_id' => $entry['menu_item_id'],
                'item_name' => $entry['name'],
                'quantity' => $entry['quantity'] ?? 1,
                'unit_price' => $entry['price'] ?? 1000,
                'total_price' => ($entry['price'] ?? 1000) * ($entry['quantity'] ?? 1),
                'options_total' => 0,
                'kitchen_status' => $entry['status'] ?? 'pending',
            ]);
        }

        $order->recalculate();

        return $order->fresh();
    }

    // ------------------------------------------------------------------
    // 1. toKitchenArray expose preparation_time par item
    // ------------------------------------------------------------------

    public function test_to_kitchen_array_exposes_preparation_time_per_item(): void
    {
        $yassa = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Yassa',
            'price' => 5500,
            'preparation_time' => 25,
        ]);
        $bissap = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Bissap',
            'price' => 1500,
            'preparation_time' => 5,
        ]);

        $order = $this->makeInProgressOrder([
            ['menu_item_id' => $yassa->id, 'name' => 'Yassa', 'quantity' => 1, 'price' => 5500],
            ['menu_item_id' => $bissap->id, 'name' => 'Bissap', 'quantity' => 2, 'price' => 1500],
        ]);

        $order->load('items.menuItem');
        $data = $order->toKitchenArray();

        $this->assertCount(2, $data['items']);
        $prep = collect($data['items'])->pluck('preparation_time', 'item_name');
        $this->assertSame(25, $prep['Yassa']);
        $this->assertSame(5, $prep['Bissap']);
    }

    // ------------------------------------------------------------------
    // 2. estimated_prep_time = somme des preparation_time des items
    // ------------------------------------------------------------------

    public function test_estimated_prep_time_is_the_sum_of_item_prep_times(): void
    {
        $dish1 = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Plat 1',
            'price' => 3000,
            'preparation_time' => 15,
        ]);
        $dish2 = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Plat 2',
            'price' => 4000,
            'preparation_time' => 10,
        ]);
        $dish3 = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Plat 3',
            'price' => 5000,
            'preparation_time' => 20,
        ]);

        $order = $this->makeInProgressOrder([
            ['menu_item_id' => $dish1->id, 'name' => 'Plat 1', 'quantity' => 1, 'price' => 3000],
            ['menu_item_id' => $dish2->id, 'name' => 'Plat 2', 'quantity' => 2, 'price' => 4000],
            ['menu_item_id' => $dish3->id, 'name' => 'Plat 3', 'quantity' => 1, 'price' => 5000],
        ]);

        $order->load('items.menuItem');
        $data = $order->toKitchenArray();

        // Somme SANS multiplier par la quantité : 15 + 10 + 20 = 45
        $this->assertSame(45, $data['estimated_prep_time']);
    }

    // ------------------------------------------------------------------
    // 3. Commande sans item → estimated_prep_time = 0
    // ------------------------------------------------------------------

    public function test_estimated_prep_time_is_zero_for_an_empty_order(): void
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'type' => 'dine_in',
            'user_id' => $this->admin->id,
            'status' => 'in_progress',
        ]);

        $order->load('items');
        $data = $order->toKitchenArray();

        $this->assertSame(0, $data['estimated_prep_time']);
        $this->assertSame([], $data['items']);
    }

    // ------------------------------------------------------------------
    // 4. MenuController::store et update acceptent preparation_time
    // ------------------------------------------------------------------

    public function test_menu_item_store_accepts_preparation_time(): void
    {
        $this->actingAsRole('admin');

        $response = $this->postJson('/api/menu', [
            'name' => 'Nouveau plat',
            'price' => 5000,
            'preparation_time' => 20,
        ])->assertCreated();

        $this->assertSame(20, $response->json('preparation_time'));
    }

    public function test_menu_item_store_defaults_preparation_time_to_15(): void
    {
        $this->actingAsRole('admin');

        $response = $this->postJson('/api/menu', [
            'name' => 'Nouveau plat',
            'price' => 5000,
        ])->assertCreated();

        // Le défaut est géré par la migration : 15
        $this->assertSame(15, $response->json('preparation_time'));
    }

    public function test_menu_item_update_accepts_preparation_time(): void
    {
        $this->actingAsRole('admin');

        $dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Plat',
            'price' => 3000,
            'preparation_time' => 15,
        ]);

        $this->putJson("/api/menu/{$dish->id}", [
            'preparation_time' => 30,
        ])->assertOk();

        $this->assertSame(30, $dish->fresh()->preparation_time);
    }
}
