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

class MenuItemDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private User $admin;

    private Table $table;

    private MenuItem $menuItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();

        $this->admin = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'admin',
        ]);

        $this->table = Table::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => 'free',
        ]);

        $this->menuItem = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
        ]);

        Sanctum::actingAs($this->admin);
    }

    private function createOrder(string $status = 'open'): Order
    {
        return Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => $this->admin->id,
            'order_number' => (string) Str::uuid(),
            'status' => $status,
        ]);
    }

    private function addItemToOrder(
        Order $order,
        MenuItem $menuItem,
        int $price = 3000
    ): OrderItem {
        return OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => $menuItem->id,
            'item_name' => $menuItem->name,
            'quantity' => 1,
            'unit_price' => $price,
            'total_price' => $price,
            'kitchen_status' => 'pending',
        ]);
    }

    public function test_menu_item_without_orders_can_be_deleted(): void
    {
        $this->deleteJson("/api/menu/{$this->menuItem->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('menu_items', [
            'id' => $this->menuItem->id,
        ]);
    }

    public function test_menu_item_used_only_in_open_orders_can_be_deleted(): void
    {
        $firstOrder = $this->createOrder('open');
        $secondOrder = $this->createOrder('open');

        $this->addItemToOrder($firstOrder, $this->menuItem);
        $this->addItemToOrder($secondOrder, $this->menuItem);

        $this->deleteJson("/api/menu/{$this->menuItem->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('menu_items', [
            'id' => $this->menuItem->id,
        ]);

        $this->assertDatabaseMissing('order_items', [
            'menu_item_id' => $this->menuItem->id,
        ]);

        $this->assertSame('open', $firstOrder->fresh()->status);
        $this->assertSame('open', $secondOrder->fresh()->status);
    }

    public function test_deleting_menu_item_keeps_other_items_in_open_order(): void
    {
        $order = $this->createOrder('open');

        $otherMenuItem = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Autre plat',
            'price' => 5000,
        ]);

        $this->addItemToOrder($order, $this->menuItem);
        $otherOrderItem = $this->addItemToOrder($order, $otherMenuItem, 5000);
        $this->deleteJson("/api/menu/{$this->menuItem->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('order_items', [
            'menu_item_id' => $this->menuItem->id,
        ]);

        $this->assertDatabaseHas('order_items', [
            'id' => $otherOrderItem->id,
            'menu_item_id' => $otherMenuItem->id,
        ]);

        $this->assertSame(5000, $order->fresh()->subtotal);
        $this->assertSame('open', $order->fresh()->status);
    }

    public function test_menu_item_cannot_be_deleted_if_used_in_a_non_open_order(): void
    {
        $statuses = [
            'in_progress',
            'ready',
            'served',
            'paid',
            'cancelled',
        ];

        foreach ($statuses as $status) {
            $order = $this->createOrder($status);
            $this->addItemToOrder($order, $this->menuItem);
        }

        $this->deleteJson("/api/menu/{$this->menuItem->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('menu_items', [
            'id' => $this->menuItem->id,
        ]);

        $this->assertDatabaseCount('order_items', count($statuses));
    }
}
