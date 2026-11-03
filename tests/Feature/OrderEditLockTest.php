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

class OrderEditLockTest extends TestCase
{
    use RefreshDatabase;

    private const LOCKED_MESSAGE = 'Impossible de modifier cette commande : elle a déjà été envoyée en cuisine.';

    private Restaurant $restaurant;

    private User $admin;

    private Table $table;

    private MenuItem $dish;

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
            'status' => 'occupied',
        ]);
        $this->dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'price' => 1000,
        ]);

        Sanctum::actingAs($this->admin);
    }

    private function orderWithStatus(string $status): Order
    {
        return Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => $this->admin->id,
            'status' => $status,
        ]);
    }

    private function itemFor(Order $order): OrderItem
    {
        return OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => $this->dish->id,
            'item_name' => $this->dish->name,
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
            'kitchen_status' => 'pending',
        ]);
    }

    public function test_items_can_be_added_and_removed_while_the_order_is_open(): void
    {
        $order = $this->orderWithStatus('open');

        $itemId = $this->postJson("/api/orders/{$order->id}/items", [
            'menu_item_id' => $this->dish->id,
            'quantity' => 1,
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/orders/{$order->id}/items/{$itemId}")->assertOk();
    }

    public function test_items_cannot_be_added_once_the_order_left_open(): void
    {
        foreach (['in_progress', 'ready', 'served', 'paid', 'cancelled'] as $status) {
            $order = $this->orderWithStatus($status);

            $this->postJson("/api/orders/{$order->id}/items", [
                'menu_item_id' => $this->dish->id,
                'quantity' => 1,
            ])
                ->assertStatus(422)
                ->assertJson(['success' => false, 'message' => self::LOCKED_MESSAGE]);

            $this->assertSame(0, $order->items()->count(), "Un plat a été ajouté à une commande {$status}");
        }
    }

    public function test_items_cannot_be_removed_once_the_order_left_open(): void
    {
        foreach (['in_progress', 'ready', 'served', 'paid', 'cancelled'] as $status) {
            $order = $this->orderWithStatus($status);
            $item = $this->itemFor($order);

            $this->deleteJson("/api/orders/{$order->id}/items/{$item->id}")
                ->assertStatus(422)
                ->assertJson(['success' => false, 'message' => self::LOCKED_MESSAGE]);

            $this->assertDatabaseHas('order_items', ['id' => $item->id]);
        }
    }
}
