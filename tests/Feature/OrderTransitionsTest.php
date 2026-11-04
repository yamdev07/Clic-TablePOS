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

class OrderTransitionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Restaurant $restaurant;

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

    /** Avec $total > 0 : un plat de 1000 (+ 18 % + 5 %) = 1230, comme le calcule recalculate(). */
    private function order(string $status, int $total = 0): Order
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => $this->admin->id,
            'status' => $status,
            'subtotal' => $total > 0 ? 1000 : 0,
            'tax' => $total > 0 ? 180 : 0,
            'service_charge' => $total > 0 ? 50 : 0,
            'total' => $total,
            'due_amount' => $total,
        ]);

        if ($total > 0) {
            OrderItem::create([
                'id' => (string) Str::uuid(),
                'order_id' => $order->id,
                'menu_item_id' => $this->dish->id,
                'item_name' => $this->dish->name,
                'quantity' => 1,
                'unit_price' => 1000,
                'total_price' => 1000,
                'kitchen_status' => 'served',
            ]);
        }

        $this->table->update(['status' => 'occupied', 'current_order_id' => $order->id]);

        return $order;
    }

    public function test_confirm_and_send_to_kitchen_only_work_from_open(): void
    {
        foreach (['confirm', 'send-to-kitchen'] as $route) {
            foreach (['in_progress', 'ready', 'served', 'paid', 'cancelled'] as $status) {
                $order = $this->order($status);

                $this->postJson("/api/orders/{$order->id}/{$route}")
                    ->assertStatus(422)
                    ->assertJson(['success' => false, 'message' => 'Cette commande ne peut plus être envoyée en cuisine.']);

                $this->assertSame($status, $order->fresh()->status, "{$route} sur {$status}");
            }

            $open = $this->order('open');
            $this->postJson("/api/orders/{$open->id}/{$route}")->assertOk();
            $this->assertSame('in_progress', $open->fresh()->status, $route);
        }
    }

    public function test_a_paid_or_cancelled_order_status_cannot_change(): void
    {
        foreach (['paid', 'cancelled'] as $status) {
            foreach (['open', 'in_progress', 'ready', 'served'] as $target) {
                $order = $this->order($status);

                $this->patchJson("/api/orders/{$order->id}/status", ['status' => $target])
                    ->assertStatus(422)
                    ->assertJson(['success' => false, 'message' => 'Cette commande est terminée : son statut ne peut plus changer.']);

                $this->assertSame($status, $order->fresh()->status, "{$status} -> {$target}");
            }
        }
    }

    public function test_a_cancelled_order_cannot_be_paid(): void
    {
        $order = $this->order('cancelled', 1230);

        $this->postJson("/api/orders/{$order->id}/payments", ['amount' => 1230, 'method' => 'cash'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Cette commande est annulée : elle ne peut pas être encaissée.']);

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_served_order_can_still_be_paid(): void
    {
        $order = $this->order('served', 1230);

        $this->postJson("/api/orders/{$order->id}/payments", ['amount' => 1230, 'method' => 'cash'])
            ->assertCreated();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('free', $this->table->fresh()->status);
    }

    public function test_status_route_cannot_mark_an_order_paid_or_reopen_it(): void
    {
        $served = $this->order('served', 1230);

        $this->patchJson("/api/orders/{$served->id}/status", ['status' => 'paid'])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Une commande passe en payée uniquement par un paiement.']);

        $this->assertSame('served', $served->fresh()->status);
        $this->assertSame('occupied', $this->table->fresh()->status);

        $inProgress = $this->order('in_progress');

        $this->patchJson("/api/orders/{$inProgress->id}/status", ['status' => 'open'])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Une commande envoyée en cuisine ne peut pas redevenir ouverte.']);

        $this->assertSame('in_progress', $inProgress->fresh()->status);
    }

    public function test_status_route_still_moves_an_order_to_ready_then_served(): void
    {
        $order = $this->order('in_progress');

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'ready'])->assertOk();
        $this->assertSame('ready', $order->fresh()->status);

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'served'])->assertOk();
        $this->assertSame('served', $order->fresh()->status);
    }

    public function test_waiter_can_mark_order_and_all_items_as_served(): void
    {
        $order = $this->order('ready');

        $firstItem = OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => $this->dish->id,
            'item_name' => $this->dish->name,
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
            'kitchen_status' => 'ready',
        ]);

        $secondItem = OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => $this->dish->id,
            'item_name' => $this->dish->name,
            'quantity' => 2,
            'unit_price' => 1000,
            'total_price' => 2000,
            'kitchen_status' => 'ready',
        ]);

        $waiter = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'waiter',
        ]);

        Sanctum::actingAs($waiter);

        $this->patchJson("/api/orders/{$order->id}/status", [
            'status' => 'served',
        ])->assertOk();

        $this->assertSame('served', $order->fresh()->status);

        $this->assertSame(
            'served',
            $firstItem->fresh()->kitchen_status
        );

        $this->assertSame(
            'served',
            $secondItem->fresh()->kitchen_status
        );
    }

    public function test_order_status_transitions_cannot_skip_or_reverse_steps(): void
    {
        $invalidTransitions = [
            'open' => ['ready', 'served'],
            'in_progress' => ['served', 'open'],
            'ready' => ['in_progress', 'open'],
            'served' => ['ready', 'in_progress', 'open'],
        ];

        foreach ($invalidTransitions as $currentStatus => $nextStatuses) {
            foreach ($nextStatuses as $nextStatus) {
                $order = $this->order($currentStatus);

                $this->patchJson("/api/orders/{$order->id}/status", [
                    'status' => $nextStatus,
                ])->assertStatus(422);

                $this->assertSame(
                    $currentStatus,
                    $order->fresh()->status,
                    "La transition {$currentStatus} → {$nextStatus} aurait dû être refusée."
                );
            }
        }
    }

    public function test_an_order_cannot_be_paid_before_being_served(): void
    {
        foreach (['open', 'in_progress', 'ready'] as $status) {
            $order = $this->order($status, 1230);

            $this->postJson("/api/orders/{$order->id}/payments", [
                'amount' => 1230,
                'method' => 'cash',
            ])
                ->assertStatus(422)
                ->assertJson([
                    'message' => 'Une commande doit être servie avant de pouvoir être encaissée.',
                ]);

            $this->assertSame($status, $order->fresh()->status);
        }

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_kitchen_cannot_update_global_order_status_directly(): void
    {
        $kitchen = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'kitchen',
        ]);

        $order = $this->order('open');

        Sanctum::actingAs($kitchen);

        $this->patchJson("/api/orders/{$order->id}/status", [
            'status' => 'in_progress',
        ])->assertForbidden();

        $this->assertSame('open', $order->fresh()->status);
    }

    public function test_admin_and_manager_can_mark_order_as_served(): void
    {
        $manager = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'manager',
        ]);

        foreach ([$this->admin, $manager] as $user) {
            $order = $this->order('ready');

            Sanctum::actingAs($user);

            $this->patchJson("/api/orders/{$order->id}/status", [
                'status' => 'served',
            ])->assertOk();

            $this->assertSame('served', $order->fresh()->status);
        }
    }
    public function test_kitchen_cannot_mark_order_as_served(): void
{
    $kitchen = User::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'role' => 'kitchen',
    ]);

    $order = $this->order('ready');

    Sanctum::actingAs($kitchen);

    $this->patchJson("/api/orders/{$order->id}/status", [
        'status' => 'served',
    ])->assertForbidden();

    $this->assertSame('ready', $order->fresh()->status);
}
public function test_waiter_cannot_mark_another_restaurants_order_as_served(): void
{
    $otherRestaurant = Restaurant::factory()->create();

    $otherOrder = Order::factory()->create([
        'restaurant_id' => $otherRestaurant->id,
        'status' => 'ready',
    ]);

    $waiter = User::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'role' => 'waiter',
    ]);

    Sanctum::actingAs($waiter);

    $this->patchJson("/api/orders/{$otherOrder->id}/status", [
        'status' => 'served',
    ])->assertNotFound();

    $this->assertSame('ready', $otherOrder->fresh()->status);
}
}
