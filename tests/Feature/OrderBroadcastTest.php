<?php

namespace Tests\Feature;

use App\Events\OrderCancelled;
use App\Events\OrderStatusChanged;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelling_an_order_broadcasts_each_event_once(): void
    {
        Event::fake([OrderStatusChanged::class, OrderCancelled::class]);

        $restaurant = Restaurant::factory()->create();
        $waiter = User::factory()->create(['restaurant_id' => $restaurant->id, 'role' => 'waiter']);
        $table = Table::factory()->create(['restaurant_id' => $restaurant->id, 'status' => 'occupied']);
        $dish = MenuItem::factory()->create(['restaurant_id' => $restaurant->id]);

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'table_id' => $table->id,
            'user_id' => $waiter->id,
            'status' => 'in_progress',
        ]);

        OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => $dish->id,
            'item_name' => $dish->name,
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
            'kitchen_status' => 'pending',
        ]);

        Sanctum::actingAs($waiter);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
        Event::assertDispatchedTimes(OrderCancelled::class, 1);
    }
}
