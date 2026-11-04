<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderRecalculateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Table $table;

    private Order $order;

    private MenuItem $dish;

    protected function setUp(): void
    {
        parent::setUp();

        $restaurant = Restaurant::factory()->create();

        $this->admin = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'role' => 'admin',
        ]);

        $this->table = Table::factory()->create([
            'restaurant_id' => $restaurant->id,
            'status' => 'free',
        ]);

        $this->dish = MenuItem::factory()->create([
            'restaurant_id' => $restaurant->id,
            'price' => 1000,
        ]);

        $this->order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'table_id' => $this->table->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
        ]);

        $this->table->update(['status' => 'occupied', 'current_order_id' => $this->order->id]);

        Sanctum::actingAs($this->admin);
    }
        private function newOrder(int $minutesAgo): Order
    {
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->order->restaurant_id,
            'table_id' => $this->table->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
        ]);
        $order->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $order;
    }

    private function addItemAndPrice(Order $order): void
    {
        $order->items()->create([
            'menu_item_id' => $this->dish->id,
            'item_name' => $this->dish->name,
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
        ]);
        $order->recalculate();
    }

    private function pay(Order $order): void
    {
        $this->postJson("/api/orders/{$order->id}/payments", [
            'amount' => $order->fresh()->total,
            'method' => 'cash',
        ])->assertCreated();
    }

    public function test_removing_the_last_item_does_not_mark_the_order_paid(): void
    {
        $itemId = $this->postJson("/api/orders/{$this->order->id}/items", [
            'menu_item_id' => $this->dish->id,
            'quantity' => 1,
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/orders/{$this->order->id}/items/{$itemId}")->assertOk();

        $this->assertSame('open', $this->order->fresh()->status);
        $this->assertSame('occupied', $this->table->fresh()->status);
        $this->assertSame($this->order->id, $this->table->fresh()->current_order_id);
    }

    public function test_a_fully_paid_order_becomes_paid_and_frees_the_table(): void
    {
        $this->postJson("/api/orders/{$this->order->id}/items", [
            'menu_item_id' => $this->dish->id,
            'quantity' => 1,
        ])->assertCreated();

        $total = $this->order->fresh()->total;

        $this->postJson("/api/orders/{$this->order->id}/payments", [
            'amount' => $total,
            'method' => 'cash',
        ])->assertCreated();

        $this->assertSame('paid', $this->order->fresh()->status);
        $this->assertSame('free', $this->table->fresh()->status);
    }

    public function test_paying_one_order_does_not_free_table_while_another_order_is_active(): void
    {
        // Créer un plat pour la première commande
        $this->postJson("/api/orders/{$this->order->id}/items", [
            'menu_item_id' => $this->dish->id,
            'quantity' => 1,
        ])->assertCreated();

        // Créer une deuxième commande sur la même table
        $secondOrder = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->order->restaurant_id,
            'table_id' => $this->table->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
        ]);



        // Payer entièrement la première commande
        $this->postJson("/api/orders/{$this->order->id}/payments", [
            'amount' => $this->order->fresh()->total,
            'method' => 'cash',
        ])->assertCreated();

        $this->assertSame('paid', $this->order->fresh()->status);
        $this->assertSame('open', $secondOrder->fresh()->status);

        // La table doit rester occupée
        $this->assertSame('occupied', $this->table->fresh()->status);

                // La première commande est terminée : la table pointe vers celle qui reste active
        $this->assertSame($secondOrder->id, $this->table->fresh()->current_order_id);
    }
        public function test_table_points_to_the_oldest_active_order_until_all_are_closed(): void
    {
        $first = $this->order;
        $first->forceFill(['created_at' => now()->subMinutes(30)])->save();
        $second = $this->newOrder(20);
        $third = $this->newOrder(10);

        foreach ([$first, $second, $third] as $order) {
            $this->addItemAndPrice($order);
        }

        $this->pay($first);
        $this->assertSame('occupied', $this->table->fresh()->status);
        $this->assertSame($second->id, $this->table->fresh()->current_order_id);

        $this->pay($second);
        $this->assertSame('occupied', $this->table->fresh()->status);
        $this->assertSame($third->id, $this->table->fresh()->current_order_id);

        $this->pay($third);
        $this->assertSame('free', $this->table->fresh()->status);
        $this->assertNull($this->table->fresh()->current_order_id);
    }
}
