<?php

// tests/Feature/OrderApiTest.php

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

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Table $table;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Restaurant',
            'slug' => 'test-restaurant',
            'email' => 'test@restaurant.com',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->table = Table::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'number' => '1',
            'status' => 'free',
            'qr_code' => 'test-qr-code',
        ]);

        Sanctum::actingAs($this->user);
    }

    /** @test */
    public function user_can_create_order()
    {
        $response = $this->postJson('/api/orders', [
            'table_id' => $this->table->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => ['id', 'order_number', 'status', 'table_id'],
            ]);
        $this->assertDatabaseHas('tables', [
            'id' => $this->table->id,
            'status' => 'free',
            'current_order_id' => null,
        ]);
    }

    /** @test */
    public function user_can_create_another_order_on_occupied_table_without_replacing_current_order()
    {
        $firstOrder = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => $this->user->id,
            'status' => 'in_progress',
            'order_number' => 'ORD-FIRST-001',
        ]);

        $this->table->update([
            'status' => 'occupied',
            'current_order_id' => $firstOrder->id,
        ]);

        $response = $this->postJson('/api/orders', [
            'table_id' => $this->table->id,
        ]);

        $response->assertStatus(201);

        $secondOrderId = $response->json('data.id');

        $this->assertNotEquals($firstOrder->id, $secondOrderId);

        $this->assertDatabaseHas('orders', [
            'id' => $firstOrder->id,
            'table_id' => $this->table->id,
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $secondOrderId,
            'table_id' => $this->table->id,
        ]);

        $this->assertDatabaseHas('tables', [
            'id' => $this->table->id,
            'status' => 'occupied',
            'current_order_id' => $firstOrder->id,
        ]);
    }

    /** @test */
    public function user_can_add_item_to_order()
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => $this->user->id,
            'status' => 'open',
            'order_number' => 'ORD-TEST-001',
        ]);

        $menuItem = MenuItem::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Test Burger',
            'price' => 5000,
            'is_available' => true,
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/items", [
            'menu_item_id' => $menuItem->id,
            'quantity' => 2,
        ]);

        $response->assertStatus(201);
    }

    /** @test */
    public function user_can_send_order_to_kitchen()
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => $this->user->id,
            'status' => 'open',
            'order_number' => 'ORD-TEST-002',
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/send-to-kitchen");

        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'in_progress',
        ]);
    }

    /** @test */
    public function user_cannot_create_order_on_reserved_or_dirty_table()
    {
        foreach (['reserved', 'dirty'] as $status) {
            $this->table->update([
                'status' => $status,
                'current_order_id' => null,
            ]);

            $response = $this->postJson('/api/orders', [
                'table_id' => $this->table->id,
            ]);

            $response->assertStatus(422);
        }
    }

    /** @test */
    public function user_can_see_out_of_stock_information_in_order_details()
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => $this->user->id,
            'status' => 'in_progress',
            'order_number' => 'ORD-RUPTURE-001',
        ]);
        $menuItem = MenuItem::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Poulet braisé',
            'price' => 1000,
            'is_available' => false,
            'is_active' => true,
        ]);

        $item = OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'item_name' => 'Poulet braisé',
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
            'kitchen_status' => 'served',
            'menu_item_id' => $menuItem->id,
            'out_of_stock_at' => now(),
        ]);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk()
            ->assertJsonPath('items.0.id', $item->id)
            ->assertJsonPath('items.0.item_name', 'Poulet braisé')
            ->assertJsonPath('items.0.kitchen_status', 'served');

        $this->assertNotNull($response->json('items.0.out_of_stock_at'));
    }
}
