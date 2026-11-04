<?php

namespace Tests\Feature;

use App\Events\OrderCancelled;
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

class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    private const STARTED_MESSAGE = "Impossible d'annuler cette commande : la préparation d'au moins un plat a déjà commencé.";

    private const CLOSED_MESSAGE = 'Cette commande ne peut plus être annulée.';

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

    /** Crée une commande avec un plat par statut de cuisine donné. */
    private function order(string $orderStatus, array $itemStatuses = ['pending']): Order
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => $orderStatus,
        ]);

        foreach ($itemStatuses as $kitchenStatus) {
            OrderItem::create([
                'id' => (string) Str::uuid(),
                'order_id' => $order->id,
                'menu_item_id' => $this->dish->id,
                'item_name' => $this->dish->name,
                'quantity' => 1,
                'unit_price' => 1000,
                'total_price' => 1000,
                'kitchen_status' => $kitchenStatus,
            ]);
        }

        $this->table->update(['status' => 'occupied', 'current_order_id' => $order->id]);

        return $order;
    }

    /** Les deux routes qui peuvent annuler une commande. */
    private function cancel(Order $order, string $route)
    {
        return $route === 'cancel'
            ? $this->postJson("/api/orders/{$order->id}/cancel")
            : $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'cancelled']);
    }

    public function test_an_open_order_can_be_cancelled(): void
    {
        $this->actingAsRole('waiter');
        $order = $this->order('open', []);

        $this->cancel($order, 'cancel')->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_a_sent_order_with_only_pending_items_can_be_cancelled(): void
    {
        $this->actingAsRole('waiter');

        foreach (['cancel', 'status'] as $route) {
            $order = $this->order('in_progress', ['pending', 'pending', 'pending']);

            $this->cancel($order, $route)->assertOk();

            $this->assertSame('cancelled', $order->fresh()->status, "route {$route}");
            $this->assertSame('free', $this->table->fresh()->status, "route {$route}");
            $this->assertNull($this->table->fresh()->current_order_id, "route {$route}");
        }
    }

    public function test_cancelling_one_order_does_not_free_table_while_another_order_is_active(): void
    {
        $this->actingAsRole('waiter');

        $firstOrder = $this->order('in_progress', ['pending']);

        $secondOrder = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => User::factory()->create([
                'restaurant_id' => $this->restaurant->id,
            ])->id,
            'status' => 'in_progress',
        ]);

        $this->cancel($firstOrder, 'cancel')->assertOk();

        $this->assertSame('cancelled', $firstOrder->fresh()->status);
        $this->assertSame('in_progress', $secondOrder->fresh()->status);
        $this->assertSame('occupied', $this->table->fresh()->status);
        $this->assertSame($secondOrder->id, $this->table->fresh()->current_order_id);
    }

    public function test_waiter_manager_and_admin_can_cancel(): void
    {
        foreach (['waiter', 'manager', 'admin'] as $role) {
            $this->actingAsRole($role);
            $order = $this->order('in_progress', ['pending']);

            $this->cancel($order, 'cancel')->assertOk();

            $this->assertSame('cancelled', $order->fresh()->status, "rôle {$role}");
        }
    }

    public function test_kitchen_cannot_cancel(): void
    {
        $this->actingAsRole('kitchen');

        foreach (['cancel', 'status'] as $route) {
            $order = $this->order('in_progress', ['pending']);

            $this->cancel($order, $route)->assertForbidden();

            $this->assertSame('in_progress', $order->fresh()->status, "route {$route}");
        }
    }

    public function test_cannot_cancel_once_preparation_has_started(): void
    {
        $this->actingAsRole('waiter');

        // [statut de la commande, statuts de cuisine de ses plats]
        $cases = [
            ['in_progress', ['cooking', 'pending', 'pending']],   // un seul plat en cuisson suffit
            ['in_progress', ['pending', 'ready']],
            ['in_progress', ['pending', 'served']],
            ['ready', ['ready', 'ready']],
            ['served', ['served']],
        ];

        foreach (['cancel', 'status'] as $route) {
            foreach ($cases as [$orderStatus, $itemStatuses]) {
                $order = $this->order($orderStatus, $itemStatuses);

                $this->cancel($order, $route)
                    ->assertStatus(422)
                    ->assertJson(['success' => false, 'message' => self::STARTED_MESSAGE]);

                $this->assertSame($orderStatus, $order->fresh()->status, "route {$route}");
                $this->assertSame('occupied', $this->table->fresh()->status);
            }
        }
    }

    public function test_paid_or_cancelled_orders_cannot_be_cancelled(): void
    {
        $this->actingAsRole('waiter');

        foreach (['cancel', 'status'] as $route) {
            foreach (['paid', 'cancelled'] as $status) {
                $order = $this->order($status, ['served']);

                $this->cancel($order, $route)
                    ->assertStatus(422)
                    ->assertJson(['success' => false, 'message' => self::CLOSED_MESSAGE]);

                $this->assertSame($status, $order->fresh()->status, "route {$route}");
            }
        }
    }

    public function test_a_cancelled_order_disappears_from_the_kitchen_queue(): void
    {
        $this->actingAsRole('waiter');
        $order = $this->order('in_progress', ['pending']);
        $itemId = $order->items()->first()->id;

        $this->cancel($order, 'cancel')->assertOk();

        $this->actingAsRole('kitchen');
        $ids = collect($this->getJson('/api/kitchen/pending')->assertOk()->json())->pluck('id');

        $this->assertFalse($ids->contains($itemId));
    }

    public function test_kitchen_cannot_start_an_item_of_a_cancelled_or_paid_order(): void
    {
        foreach (['cancelled', 'paid'] as $status) {
            $this->actingAsRole('waiter');
            $order = $this->order($status, ['pending']);
            $item = $order->items()->first();

            $this->actingAsRole('kitchen');
            $this->patchJson("/api/kitchen/items/{$item->id}/cooking")
                ->assertStatus(422)
                ->assertJson(['message' => "Cette commande n'est plus active : impossible de commencer la préparation."]);

            $this->assertSame('pending', $item->fresh()->kitchen_status, "commande {$status}");
            $this->assertNull($item->fresh()->started_at, "commande {$status}");
        }
    }

    public function test_cancelling_is_refused_after_the_cook_started_an_item(): void
    {
        $this->actingAsRole('waiter');
        $order = $this->order('in_progress', ['pending', 'pending']);
        $pizza = $order->items()->first();

        // le cuisinier commence la pizza
        $this->actingAsRole('kitchen');
        $this->patchJson("/api/kitchen/items/{$pizza->id}/cooking")->assertOk();

        // puis le serveur tente d'annuler
        $this->actingAsRole('waiter');
        $this->cancel($order, 'cancel')
            ->assertStatus(422)
            ->assertJson(['message' => self::STARTED_MESSAGE]);

        $this->assertSame('in_progress', $order->fresh()->status);
    }

    public function test_cancelling_broadcasts_order_cancelled(): void
    {
        Event::fake([OrderCancelled::class]);

        $this->actingAsRole('waiter');
        $order = $this->order('in_progress', ['pending']);

        $this->cancel($order, 'cancel')->assertOk();

        Event::assertDispatched(OrderCancelled::class, fn ($event) => $event->order->id === $order->id);
    }

    public function test_order_cancelled_event_goes_to_the_kitchen_channel_without_amounts(): void
    {
        $order = $this->order('in_progress', ['pending']);
        $event = new OrderCancelled($order);

        $channels = collect($event->broadcastOn())->map(fn ($channel) => $channel->name)->all();

        $this->assertSame(['private-kitchen.'.$this->restaurant->id], $channels);
        $this->assertSame('order.cancelled', $event->broadcastAs());
        $this->assertSame($order->id, $event->broadcastWith()['id']);
        $this->assertArrayNotHasKey('total', $event->broadcastWith());
        $this->assertArrayNotHasKey('due_amount', $event->broadcastWith());
    }
}
