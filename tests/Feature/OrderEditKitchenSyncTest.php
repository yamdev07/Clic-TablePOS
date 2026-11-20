<?php

namespace Tests\Feature;

use App\Events\KitchenOrderUpdated;
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

class OrderEditKitchenSyncTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Table $table;

    private MenuItem $dish;

    private User $waiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->table = Table::factory()->create(['restaurant_id' => $this->restaurant->id, 'status' => 'occupied']);
        $this->dish = MenuItem::factory()->create(['restaurant_id' => $this->restaurant->id, 'price' => 1000]);
        $this->waiter = User::factory()->create(['restaurant_id' => $this->restaurant->id, 'role' => 'waiter']);
        Sanctum::actingAs($this->waiter);
    }

    /** Commande avec $itemCount plats en attente ; envoyée en cuisine si $sent est vrai. */
    private function makeOrder(string $status, int $itemCount, bool $sent): Order
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'type' => 'dine_in',
            'user_id' => $this->waiter->id,
            'status' => $status,
            'confirmed_at' => $sent ? now() : null,
        ]);

        for ($i = 0; $i < $itemCount; $i++) {
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
        }

        $order->recalculate();

        return $order->fresh();
    }

    public function test_removing_an_item_from_a_sent_order_updates_the_kitchen_once(): void
    {
        Event::fake([KitchenOrderUpdated::class]);
        $order = $this->makeOrder('open', 2, true);
        $item = $order->items()->first();

        $this->deleteJson("/api/orders/{$order->id}/items/{$item->id}")->assertOk();

        Event::assertDispatchedTimes(KitchenOrderUpdated::class, 1);
        Event::assertDispatched(KitchenOrderUpdated::class, function ($event) use ($order) {
            $payload = $event->broadcastWith();

            return $event->order->id === $order->id
                && $payload['kitchen_stage'] === 'pending'
                && count($payload['items']) === 1;
        });
    }

    public function test_removing_the_last_item_of_a_sent_order_takes_it_off_the_kitchen_screen(): void
    {
        Event::fake([KitchenOrderUpdated::class]);
        $order = $this->makeOrder('open', 1, true);
        $item = $order->items()->first();

        $this->deleteJson("/api/orders/{$order->id}/items/{$item->id}")->assertOk();

        Event::assertDispatched(KitchenOrderUpdated::class, function ($event) use ($order) {
            $payload = $event->broadcastWith();

            return $event->order->id === $order->id
                && $payload['kitchen_stage'] === null
                && $payload['items'] === [];
        });

        Sanctum::actingAs(User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'kitchen',
        ]));
        $ids = collect($this->getJson('/api/kitchen/pending')->assertOk()->json())->pluck('id');
        $this->assertFalse($ids->contains($order->id));
    }

    public function test_editing_a_draft_does_not_tell_the_kitchen(): void
    {
        Event::fake([KitchenOrderUpdated::class]);
        $draft = $this->makeOrder('open', 2, false);
        $item = $draft->items()->first();

        $this->deleteJson("/api/orders/{$draft->id}/items/{$item->id}")->assertOk();

        Event::assertNotDispatched(KitchenOrderUpdated::class);
    }

    public function test_a_launched_order_cannot_be_edited_and_the_kitchen_is_not_told(): void
    {
        Event::fake([KitchenOrderUpdated::class]);
        $order = $this->makeOrder('in_progress', 1, true);
        $item = $order->items()->first();

        $this->deleteJson("/api/orders/{$order->id}/items/{$item->id}")->assertStatus(422);

        $this->assertSame(1, $order->items()->count());
        Event::assertNotDispatched(KitchenOrderUpdated::class);
    }
}
