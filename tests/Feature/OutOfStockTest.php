<?php

namespace Tests\Feature;

use App\Events\OutOfStockReported;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rupture de stock signalée par la cuisine : le plat reste visible, marqué, et retiré de la facture.
 */
class OutOfStockTest extends TestCase
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
        ]);
        $this->dish = MenuItem::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'price' => 1000,
            'is_available' => true,
        ]);

        Sanctum::actingAs(User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'kitchen',
        ]));
    }

    /** Crée une commande avec un plat pour chaque statut de cuisine donné. */
    private function order(string $status, array $itemStatuses): Order
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => $status,
        ]);

        foreach ($itemStatuses as $kitchenStatus) {
            $order->items()->create([
                'menu_item_id' => $this->dish->id,
                'item_name' => $this->dish->name,
                'quantity' => 1,
                'unit_price' => 1000,
                'total_price' => 1000,
                'kitchen_status' => $kitchenStatus,
            ]);
        }

        return $order;
    }

    private function report(OrderItem $item)
    {
        return $this->patchJson("/api/kitchen/items/{$item->id}/rupture");
    }

    public function test_a_reported_item_is_marked_explicitly(): void
    {
        $order = $this->order('in_progress', ['pending', 'pending']);
        $item = $order->items()->orderBy('id')->first();
        $other = $order->items()->where('id', '!=', $item->id)->first();

        $this->report($item)->assertOk();

        $this->assertNotNull($item->fresh()->out_of_stock_at);
        $this->assertSame('served', $item->fresh()->kitchen_status);
        $this->assertNull($other->fresh()->out_of_stock_at);
        $this->assertFalse((bool) $this->dish->fresh()->is_available);
        $this->assertSame(1000, $order->fresh()->subtotal);
    }

    public function test_a_pending_or_cooking_item_can_be_reported(): void
    {
        foreach (['pending', 'cooking'] as $kitchenStatus) {
            $order = $this->order('in_progress', [$kitchenStatus]);
            $item = $order->items()->first();

            $this->report($item)->assertOk();

            $this->assertNotNull($item->fresh()->out_of_stock_at, "plat {$kitchenStatus}");
        }
    }

    public function test_a_report_is_refused_when_the_item_or_the_order_is_not_eligible(): void
    {
        // [statut de la commande, statut du plat]
        $cases = [
            ['in_progress', 'ready'],
            ['in_progress', 'served'],
            ['open', 'pending'],
            ['ready', 'ready'],
            ['served', 'served'],
            ['paid', 'served'],
            ['cancelled', 'pending'],
        ];

        foreach ($cases as [$orderStatus, $kitchenStatus]) {
            $order = $this->order($orderStatus, [$kitchenStatus]);
            $item = $order->items()->first();

            $this->report($item)
                ->assertStatus(422)
                ->assertJsonStructure(['message']);

            $this->assertNull($item->fresh()->out_of_stock_at, "commande {$orderStatus}, plat {$kitchenStatus}");
            $this->assertTrue((bool) $this->dish->fresh()->is_available, "commande {$orderStatus}, plat {$kitchenStatus}");
        }
    }

    public function test_reporting_out_of_stock_notifies_the_restaurant_without_financial_data(): void
    {
        Queue::fake();

        $order = $this->order('in_progress', ['pending']);
        $item = $order->items()->first();

        $this->report($item)->assertOk();

        Queue::assertPushed(
            BroadcastEvent::class,
            function (BroadcastEvent $job) use ($order, $item) {
                $event = $job->event;

                if (! $event instanceof OutOfStockReported) {
                    return false;
                }

                $channels = $event->broadcastOn();

                $this->assertEquals(
                    new PrivateChannel('restaurant.'.$this->restaurant->id),
                    $channels[0]
                );

                $payload = $event->broadcastWith();

                $this->assertSame([
                    'order_id',
                    'order_number',
                    'table_id',
                    'table_number',
                    'item_id',
                    'item_name',
                    'message',
                ], array_keys($payload));

                $this->assertSame($order->id, $payload['order_id']);
                $this->assertSame($item->id, $payload['item_id']);
                $this->assertSame($item->item_name, $payload['item_name']);

                $this->assertSame(
                    "Le plat {$item->item_name} est en rupture de stock.",
                    $payload['message']
                );

                return true;
            }
        );
    }
}
