<?php

namespace Tests\Feature;

use App\Events\KitchenOrderUpdated;
use App\Events\OrderStatusChanged;
use App\Events\WaiterCallRequested;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KitchenOrderWorkflowTest extends TestCase
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

    /** Crée une commande avec un plat pour chaque statut de cuisine donné. */
    private function order(string $status, array $itemStatuses, ?Restaurant $restaurant = null): Order
    {
        $restaurant ??= $this->restaurant;
        $table = $restaurant->is($this->restaurant)
            ? $this->table
            : Table::factory()->create(['restaurant_id' => $restaurant->id]);

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'table_id' => $table->id,
            'user_id' => User::factory()->create(['restaurant_id' => $restaurant->id])->id,
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

    private function startUrl(Order $order): string
    {
        return "/api/kitchen/orders/{$order->id}/cooking";
    }

    public function test_kitchen_can_start_the_whole_order(): void
    {
        $this->actingAsRole('kitchen');
        $order = $this->order('in_progress', ['pending', 'pending', 'pending']);

        $this->patchJson($this->startUrl($order))->assertOk();

        foreach ($order->items()->get() as $item) {
            $this->assertSame('cooking', $item->kitchen_status);
            $this->assertNotNull($item->started_at);
            $this->assertNull($item->ready_at);
        }
        $this->assertSame('in_progress', $order->fresh()->status);
    }

    public function test_an_existing_started_at_is_not_overwritten(): void
    {
        $this->actingAsRole('kitchen');
        $order = $this->order('in_progress', ['pending']);
        $item = $order->items()->first();
        $earlier = now()->subHour()->startOfSecond();
        $item->forceFill(['started_at' => $earlier])->save();

        $this->patchJson($this->startUrl($order))->assertOk();

        $this->assertTrue($item->fresh()->started_at->equalTo($earlier));
    }

    public function test_only_pending_items_are_started(): void
    {
        $this->actingAsRole('kitchen');
        $order = $this->order('in_progress', ['pending', 'served']);

        $this->patchJson($this->startUrl($order))->assertOk();

        $this->assertSame(1, $order->items()->where('kitchen_status', 'cooking')->count());
        $served = $order->items()->where('kitchen_status', 'served')->first();
        $this->assertNotNull($served);
        $this->assertNull($served->started_at);
    }

    public function test_an_order_that_is_not_in_progress_cannot_be_started(): void
    {
        $this->actingAsRole('kitchen');

        foreach (['open', 'ready', 'served', 'paid', 'cancelled'] as $status) {
            $order = $this->order($status, ['pending']);

            $this->patchJson($this->startUrl($order))
                ->assertStatus(422)
                ->assertJsonStructure(['message']);

            $item = $order->items()->first();
            $this->assertSame('pending', $item->kitchen_status, "commande {$status}");
            $this->assertNull($item->started_at, "commande {$status}");
        }
    }

    public function test_an_order_cannot_be_started_twice(): void
    {
        $this->actingAsRole('kitchen');
        $order = $this->order('in_progress', ['pending']);

        $this->patchJson($this->startUrl($order))->assertOk();
        $first = $order->items()->first()->started_at;

        $this->patchJson($this->startUrl($order))->assertStatus(422);

        $this->assertNotNull($first);
        $this->assertTrue($order->items()->first()->started_at->equalTo($first));
    }

    public function test_an_order_without_pending_items_cannot_be_started(): void
    {
        $this->actingAsRole('kitchen');

        $onlyServed = $this->order('in_progress', ['served']);
        $this->patchJson($this->startUrl($onlyServed))->assertStatus(422);

        $empty = $this->order('in_progress', []);
        $this->patchJson($this->startUrl($empty))->assertStatus(422);
    }

    public function test_only_kitchen_manager_and_admin_can_start_an_order(): void
    {
        $this->actingAsRole('waiter');
        $refused = $this->order('in_progress', ['pending']);
        $this->patchJson($this->startUrl($refused))->assertForbidden();
        $this->assertSame('pending', $refused->items()->first()->kitchen_status);

        foreach (['kitchen', 'manager', 'admin'] as $role) {
            $this->actingAsRole($role);
            $order = $this->order('in_progress', ['pending']);

            $this->patchJson($this->startUrl($order))->assertOk();
            $this->assertSame('cooking', $order->items()->first()->kitchen_status, "rôle {$role}");
        }
    }

    public function test_an_order_of_another_restaurant_cannot_be_started(): void
    {
        $this->actingAsRole('kitchen');

        // La route doit exister : sans cela, le 404 ci-dessous passerait à tort
        $own = $this->order('in_progress', ['pending']);
        $this->patchJson($this->startUrl($own))->assertOk();

        $foreign = $this->order('in_progress', ['pending'], Restaurant::factory()->create());
        $this->patchJson($this->startUrl($foreign))->assertNotFound();

        $this->assertSame('pending', $foreign->items()->first()->kitchen_status);
    }

    private function readyUrl(Order $order): string
    {
        return "/api/kitchen/orders/{$order->id}/ready";
    }

    public function test_kitchen_can_mark_the_whole_order_ready(): void
    {
        $this->actingAsRole('kitchen');
        $order = $this->order('in_progress', ['cooking', 'cooking']);

        $this->patchJson($this->readyUrl($order))->assertOk();

        foreach ($order->items()->get() as $item) {
            $this->assertSame('ready', $item->kitchen_status);
            $this->assertNotNull($item->ready_at);
        }
        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_an_existing_ready_at_is_not_overwritten(): void
    {
        $this->actingAsRole('kitchen');
        $order = $this->order('in_progress', ['cooking']);
        $item = $order->items()->first();
        $earlier = now()->subHour()->startOfSecond();
        $item->forceFill(['ready_at' => $earlier])->save();

        $this->patchJson($this->readyUrl($order))->assertOk();

        $this->assertTrue($item->fresh()->ready_at->equalTo($earlier));
    }

    public function test_only_cooking_items_are_marked_ready(): void
    {
        $this->actingAsRole('kitchen');
        $order = $this->order('in_progress', ['cooking', 'served']);

        $this->patchJson($this->readyUrl($order))->assertOk();

        $this->assertSame(1, $order->items()->where('kitchen_status', 'ready')->count());
        $served = $order->items()->where('kitchen_status', 'served')->first();
        $this->assertNotNull($served);
        $this->assertNull($served->ready_at);
        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_an_order_cannot_be_marked_ready_while_items_are_still_pending(): void
    {
        $this->actingAsRole('kitchen');

        // préparation lancée pour un seul plat, ou pas lancée du tout
        foreach ([['cooking', 'pending'], ['pending', 'pending']] as $itemStatuses) {
            $order = $this->order('in_progress', $itemStatuses);

            $this->patchJson($this->readyUrl($order))
                ->assertStatus(422)
                ->assertJsonStructure(['message']);

            $this->assertSame('in_progress', $order->fresh()->status);
            $this->assertSame(0, $order->items()->where('kitchen_status', 'ready')->count());
            $this->assertEquals(
                collect($itemStatuses)->countBy()->all(),
                $order->items()->pluck('kitchen_status')->countBy()->all()
            );
        }
    }

    public function test_an_order_that_is_not_in_progress_cannot_be_marked_ready(): void
    {
        $this->actingAsRole('kitchen');

        foreach (['open', 'ready', 'served', 'paid', 'cancelled'] as $status) {
            $order = $this->order($status, ['cooking']);

            $this->patchJson($this->readyUrl($order))->assertStatus(422);

            $this->assertSame($status, $order->fresh()->status, "commande {$status}");
            $this->assertSame('cooking', $order->items()->first()->kitchen_status, "commande {$status}");
        }
    }

    public function test_an_order_without_cooking_items_cannot_be_marked_ready(): void
    {
        $this->actingAsRole('kitchen');

        $onlyServed = $this->order('in_progress', ['served']);
        $this->patchJson($this->readyUrl($onlyServed))->assertStatus(422);

        $empty = $this->order('in_progress', []);
        $this->patchJson($this->readyUrl($empty))->assertStatus(422);

        $this->assertSame('in_progress', $onlyServed->fresh()->status);
        $this->assertSame('in_progress', $empty->fresh()->status);
    }

    public function test_only_kitchen_manager_and_admin_can_mark_an_order_ready(): void
    {
        $this->actingAsRole('waiter');
        $refused = $this->order('in_progress', ['cooking']);
        $this->patchJson($this->readyUrl($refused))->assertForbidden();
        $this->assertSame('in_progress', $refused->fresh()->status);

        foreach (['kitchen', 'manager', 'admin'] as $role) {
            $this->actingAsRole($role);
            $order = $this->order('in_progress', ['cooking']);

            $this->patchJson($this->readyUrl($order))->assertOk();
            $this->assertSame('ready', $order->fresh()->status, "rôle {$role}");
        }
    }

    public function test_an_order_of_another_restaurant_cannot_be_marked_ready(): void
    {
        $this->actingAsRole('kitchen');
        $foreign = $this->order('in_progress', ['cooking'], Restaurant::factory()->create());

        $this->patchJson($this->readyUrl($foreign))->assertNotFound();

        $this->assertSame('in_progress', $foreign->fresh()->status);
        $this->assertSame('cooking', $foreign->items()->first()->kitchen_status);
    }

    public function test_marking_ready_broadcasts_the_order_status_change_once(): void
    {
        Event::fake([OrderStatusChanged::class]);
        $this->actingAsRole('kitchen');
        $order = $this->order('in_progress', ['cooking', 'cooking']);

        $this->patchJson($this->readyUrl($order))->assertOk();

        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
        Event::assertDispatched(
            OrderStatusChanged::class,
            fn ($event) => $event->order->id === $order->id
                && $event->oldStatus === 'in_progress'
                && $event->order->status === 'ready'
        );
    }

    private function callUrl(Order $order): string
    {
        return "/api/kitchen/orders/{$order->id}/call-waiter";
    }

    public function test_kitchen_can_call_the_waiter_for_a_ready_order_without_changing_anything(): void
    {
        Event::fake([WaiterCallRequested::class]);
        $this->actingAsRole('kitchen');
        $order = $this->order('ready', ['ready', 'ready']);

        $this->patchJson($this->readyUrl($order))->assertStatus(422); // déjà prête : le workflow ne rejoue pas
        $this->postJson($this->callUrl($order))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('ready', $order->fresh()->status);
        $this->assertSame(2, $order->items()->where('kitchen_status', 'ready')->count());
        Event::assertDispatchedTimes(WaiterCallRequested::class, 1);
    }

    public function test_the_waiter_cannot_be_called_unless_the_order_is_ready(): void
    {
        Event::fake([WaiterCallRequested::class]);
        $this->actingAsRole('kitchen');

        foreach (['open', 'in_progress', 'served', 'paid', 'cancelled'] as $status) {
            $order = $this->order($status, ['ready']);

            $this->postJson($this->callUrl($order))
                ->assertStatus(422)
                ->assertJsonStructure(['message']);

            $this->assertSame($status, $order->fresh()->status);
        }

        Event::assertNotDispatched(WaiterCallRequested::class);
    }

    public function test_a_waiter_cannot_call_the_waiter(): void
    {
        Event::fake([WaiterCallRequested::class]);
        $this->actingAsRole('waiter');
        $order = $this->order('ready', ['ready']);

        $this->postJson($this->callUrl($order))->assertForbidden();

        Event::assertNotDispatched(WaiterCallRequested::class);
    }

    public function test_kitchen_manager_and_admin_can_call_the_waiter(): void
    {
        Event::fake([WaiterCallRequested::class]);

        foreach (['kitchen', 'manager', 'admin'] as $role) {
            $this->actingAsRole($role);
            $order = $this->order('ready', ['ready']);

            $this->postJson($this->callUrl($order))->assertOk();
        }

        Event::assertDispatchedTimes(WaiterCallRequested::class, 3);
    }

    public function test_the_waiter_cannot_be_called_for_an_order_of_another_restaurant(): void
    {
        Event::fake([WaiterCallRequested::class]);
        $this->actingAsRole('kitchen');
        $foreign = $this->order('ready', ['ready'], Restaurant::factory()->create());

        $this->postJson($this->callUrl($foreign))->assertNotFound();

        Event::assertNotDispatched(WaiterCallRequested::class);
    }

    public function test_the_waiter_call_event_targets_the_restaurant_channel_without_amounts(): void
    {
        $order = $this->order('ready', ['ready']);
        $event = new WaiterCallRequested($order);

        $channels = collect((array) $event->broadcastOn())->map(fn ($channel) => $channel->name)->all();

        $this->assertSame(['private-restaurant.'.$this->restaurant->id], $channels);
        $this->assertSame('waiter.call.requested', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $json = json_encode($payload);

        $this->assertStringContainsString($order->id, $json);
        $this->assertStringContainsString($order->order_number, $json);

        foreach (['total', 'subtotal', 'due_amount', 'paid_amount'] as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }
    }

    public function test_starting_the_order_sends_one_kitchen_event_instead_of_one_per_item(): void
    {
        Event::fake([KitchenOrderUpdated::class]);
        $this->actingAsRole('kitchen');
        $order = $this->order('in_progress', ['pending', 'pending', 'pending']);

        $this->patchJson($this->startUrl($order))->assertOk();

        Event::assertDispatchedTimes(KitchenOrderUpdated::class, 1);
        Event::assertDispatched(KitchenOrderUpdated::class, fn ($event) => $event->order->id === $order->id);
    }

    public function test_marking_ready_sends_one_kitchen_event_and_still_tells_the_waiters(): void
    {
        Event::fake([KitchenOrderUpdated::class, OrderStatusChanged::class]);
        $this->actingAsRole('kitchen');
        $order = $this->order('in_progress', ['cooking', 'cooking']);

        $this->patchJson($this->readyUrl($order))->assertOk();

        Event::assertDispatchedTimes(KitchenOrderUpdated::class, 1);
        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
    }

    public function test_confirming_the_service_tells_the_kitchen(): void
    {
        Event::fake([KitchenOrderUpdated::class, OrderStatusChanged::class]);
        $this->actingAsRole('waiter');
        $order = $this->order('ready', ['ready', 'ready']);

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'served'])->assertOk();

        $this->assertSame('served', $order->fresh()->status);
        Event::assertDispatchedTimes(KitchenOrderUpdated::class, 1);
        Event::assertDispatched(KitchenOrderUpdated::class, fn ($event) => $event->order->id === $order->id);
        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
    }

    public function test_calling_the_waiter_does_not_send_a_kitchen_event(): void
    {
        Event::fake([KitchenOrderUpdated::class, WaiterCallRequested::class]);
        $this->actingAsRole('kitchen');
        $order = $this->order('ready', ['ready']);

        $this->postJson($this->callUrl($order))->assertOk();

        Event::assertDispatchedTimes(WaiterCallRequested::class, 1);
        Event::assertNotDispatched(KitchenOrderUpdated::class);
    }

    public function test_the_kitchen_event_targets_the_kitchen_channel_without_amounts(): void
    {
        $order = $this->order('in_progress', ['cooking', 'cooking']);
        $event = new KitchenOrderUpdated($order);

        $channels = collect($event->broadcastOn())->map(fn ($channel) => $channel->name)->all();
        $payload = $event->broadcastWith();

        $this->assertSame(['private-kitchen.'.$this->restaurant->id], $channels);
        $this->assertSame('kitchen.order.updated', $event->broadcastAs());
        $this->assertSame($order->id, $payload['id']);
        $this->assertSame($order->order_number, $payload['order_number']);
        $this->assertSame('in_progress', $payload['status']);
        $this->assertSame($this->table->number, $payload['table']['number']);
        $this->assertCount(2, $payload['items']);

        foreach (['id', 'item_name', 'quantity', 'kitchen_status', 'special_instructions', 'started_at', 'ready_at'] as $key) {
            $this->assertArrayHasKey($key, $payload['items'][0]);
        }
        foreach (['total', 'subtotal', 'due_amount', 'paid_amount'] as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }
    }

    public function test_the_kitchen_stage_follows_the_order_and_item_statuses(): void
    {
        $cases = [
            ['in_progress', ['pending', 'pending'], 'pending'],
            ['in_progress', ['pending', 'served'], 'pending'],
            ['in_progress', ['cooking', 'cooking'], 'preparing'],
            ['in_progress', ['cooking', 'pending'], 'preparing'],
            ['ready', ['ready', 'ready'], 'ready'],
            ['served', ['served'], null],
            ['paid', ['served'], null],
            ['cancelled', ['pending'], null],
            ['open', ['pending'], null],
        ];

        foreach ($cases as [$orderStatus, $itemStatuses, $expectedStage]) {
            $order = $this->order($orderStatus, $itemStatuses);

            $this->assertSame(
                $expectedStage,
                (new KitchenOrderUpdated($order))->broadcastWith()['kitchen_stage'],
                "commande {$orderStatus} avec plats ".implode(',', $itemStatuses)
            );
        }
    }

    public function test_pending_orders_returns_orders_grouped_with_their_items(): void
    {
        $this->actingAsRole('kitchen');

        $order = $this->order('in_progress', ['pending', 'cooking']);

        $response = $this->getJson('/api/kitchen/pending')->assertOk();

        $response->assertJsonCount(1);
        $response->assertJsonPath('0.id', $order->id);
        $response->assertJsonCount(2, '0.items');

        $this->assertArrayHasKey('kitchen_stage', $response->json()[0]);
        $this->assertArrayHasKey('table', $response->json()[0]);
    }

    public function test_pending_orders_returns_the_correct_kitchen_stage(): void
    {
        $this->actingAsRole('kitchen');

        $pending = $this->order('in_progress', ['pending']);
        $preparing = $this->order('in_progress', ['cooking']);
        $ready = $this->order('ready', ['ready']);

        $response = $this->getJson('/api/kitchen/pending')->assertOk();

        $orders = collect($response->json())->keyBy('id');

        $this->assertSame('pending', $orders[$pending->id]['kitchen_stage']);
        $this->assertSame('preparing', $orders[$preparing->id]['kitchen_stage']);
        $this->assertSame('ready', $orders[$ready->id]['kitchen_stage']);
    }

    public function test_pending_orders_excludes_foreign_orders_and_irrelevant_items(): void
    {
        $this->actingAsRole('kitchen');

        $valid = $this->order('in_progress', ['pending']);
        $foreign = $this->order(
            'in_progress',
            ['pending'],
            Restaurant::factory()->create()
        );
        $open = $this->order('open', ['pending']);
        $served = $this->order('in_progress', ['served']);
        $empty = $this->order('in_progress', []);

        $response = $this->getJson('/api/kitchen/pending')->assertOk();

        $ids = collect($response->json())->pluck('id');

        $this->assertTrue($ids->contains($valid->id));
        $this->assertFalse($ids->contains($foreign->id));
        $this->assertFalse($ids->contains($open->id));
        $this->assertFalse($ids->contains($served->id));
        $this->assertFalse($ids->contains($empty->id));
    }

    public function test_the_api_and_the_event_describe_a_kitchen_order_the_same_way(): void
    {
        $this->actingAsRole('kitchen');
        // un plat servi (rupture) ne doit apparaître nulle part à l'écran de la cuisine
        $order = $this->order('in_progress', ['cooking', 'pending', 'served']);

        $apiOrder = collect($this->getJson('/api/kitchen/pending')->assertOk()->json())
            ->firstWhere('id', $order->id);
        $this->assertNotNull($apiOrder);

        $eventOrder = json_decode(
            json_encode((new KitchenOrderUpdated($order->fresh()))->broadcastWith()),
            true
        );

        $this->assertCount(2, $eventOrder['items']);
        $this->assertCount(2, $apiOrder['items']);
        $this->assertSame($this->table->number, $apiOrder['table']['number']);

        $byId = function (array $payload): array {
            usort($payload['items'], fn ($a, $b) => strcmp($a['id'], $b['id']));

            return $payload;
        };

        $this->assertEquals($byId($eventOrder), $byId($apiOrder));
    }

    public function test_only_kitchen_manager_and_admin_can_read_the_kitchen_queue(): void
    {
        $this->actingAsRole('waiter');
        $order = $this->order('in_progress', ['pending']);

        $this->getJson('/api/kitchen/pending')->assertForbidden();

        foreach (['kitchen', 'manager', 'admin'] as $role) {
            $this->actingAsRole($role);

            $ids = collect($this->getJson('/api/kitchen/pending')->assertOk()->json())->pluck('id');

            $this->assertTrue($ids->contains($order->id), "rôle {$role}");
        }
    }

    public function test_signalling_out_of_stock_updates_the_kitchen_screen_by_order(): void
    {
        Queue::fake();

        $this->actingAsRole('kitchen');

        $order = $this->order('in_progress', ['pending', 'pending']);
        $outOfStock = $order->items()->orderBy('id')->first();

        $this->patchJson("/api/kitchen/items/{$outOfStock->id}/rupture")
            ->assertOk();

        Queue::assertPushed(
            BroadcastEvent::class,
            function ($job) use ($order, $outOfStock) {
                $event = $job->event;

                if (! $event instanceof KitchenOrderUpdated) {
                    return false;
                }

                $payload = $event->broadcastWith();

                $ruptureItem = collect($payload['items'])
                    ->firstWhere('id', $outOfStock->id);

                $otherItem = collect($payload['items'])
                    ->first(fn ($item) => $item['id'] !== $outOfStock->id);

                return $event->order->id === $order->id
                    && count($payload['items']) === 2
                    && $ruptureItem !== null
                    && $ruptureItem['out_of_stock'] === true
                    && $otherItem !== null
                    && $otherItem['out_of_stock'] === false;
            }
        );
    }
}
