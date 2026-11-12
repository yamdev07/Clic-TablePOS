<?php

namespace Tests\Feature;

use App\Events\TableStatusChanged;
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

class TableStatusBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Table $table;

    private MenuItem $dish;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->table = Table::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'number' => '1',
            'capacity' => 4,
            'status' => 'occupied',
            'qr_code' => 'https://example.com/'.Str::random(8),
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

    private function makeServedOrder(): Order
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => 'served',
            'type' => 'dine_in',
        ]);

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

        $order->recalculate();

        $this->table->update(['current_order_id' => $order->id]);

        return $order->fresh();
    }

    // ------------------------------------------------------------------
    // 1. Broadcast quand une commande est payée (table libérée)
    // ------------------------------------------------------------------

    public function test_broadcast_fires_when_order_is_paid_and_table_freed(): void
    {
        Event::fake([TableStatusChanged::class]);

        $this->actingAsRole('cashier');
        $order = $this->makeServedOrder();

        $this->postJson("/api/orders/{$order->id}/payments", [
            'amount' => $order->fresh()->due_amount,
            'method' => 'cash',
            'receipt_confirmed' => true,
        ])->assertCreated();

        $this->assertSame('free', $this->table->fresh()->status);
        $this->assertNull($this->table->fresh()->current_order_id);

        Event::assertDispatched(TableStatusChanged::class, function ($event) {
            return $event->tableId === $this->table->id
                && $event->status === 'free'
                && $event->currentOrderId === null;
        });
    }

    // ------------------------------------------------------------------
    // 2. Broadcast quand une commande est annulée (table libérée)
    // ------------------------------------------------------------------

    public function test_broadcast_fires_when_order_is_cancelled(): void
    {
        $this->actingAsRole('waiter');

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => User::factory()->create(['restaurant_id' => $this->restaurant->id])->id,
            'status' => 'in_progress',
            'type' => 'dine_in',
        ]);
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
        $order->recalculate();

        $this->table->update(['status' => 'occupied', 'current_order_id' => $order->id]);

        Event::fake([TableStatusChanged::class]);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        $this->assertSame('free', $this->table->fresh()->status);

        Event::assertDispatched(TableStatusChanged::class, function ($event) {
            return $event->tableId === $this->table->id
                && $event->status === 'free';
        });
    }

    // ------------------------------------------------------------------
    // 3. Broadcast sur changement manuel (PATCH /tables/{id}/status)
    // ------------------------------------------------------------------

    public function test_broadcast_fires_on_manual_status_change(): void
    {
        Event::fake([TableStatusChanged::class]);

        $this->actingAsRole('waiter');

        $this->patchJson("/api/tables/{$this->table->id}/status", [
            'status' => 'reserved',
        ])->assertOk();

        $this->assertSame('reserved', $this->table->fresh()->status);

        Event::assertDispatched(TableStatusChanged::class, function ($event) {
            return $event->tableId === $this->table->id
                && $event->status === 'reserved';
        });
    }

    // ------------------------------------------------------------------
    // 4. Pas de broadcast si statut inchangé
    // ------------------------------------------------------------------

    public function test_no_broadcast_when_status_is_unchanged(): void
    {
        Event::fake([TableStatusChanged::class]);

        $this->actingAsRole('waiter');

        // Table est déjà 'occupied', on remet 'occupied'
        $this->patchJson("/api/tables/{$this->table->id}/status", [
            'status' => 'occupied',
        ])->assertOk();

        Event::assertNotDispatched(TableStatusChanged::class);
    }
}
