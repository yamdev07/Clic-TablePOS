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

    public function test_paying_one_order_does_not_free_table_while_another_order_is_active(): void
    {
        // Créer un plat pour la première commande
        $this->postJson("/api/orders/{$this->order->id}/items", [
            'menu_item_id' => $this->dish->id,
            'quantity' => 1,
        ])->assertCreated();

        // Deuxième commande, déjà envoyée en cuisine, sur la même table
        $secondOrder = Order::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $this->order->restaurant_id,
            'table_id' => $this->table->id,
            'user_id' => $this->admin->id,
            'status' => 'in_progress',
        ]);

        // Payer entièrement la première commande
        $this->postJson("/api/orders/{$this->order->id}/payments", [
            'amount' => $this->order->fresh()->total,
            'method' => 'cash',
        ])->assertCreated();

        $this->assertSame('paid', $this->order->fresh()->status);
        $this->assertSame('in_progress', $secondOrder->fresh()->status);

        // La table reste occupée et pointe vers la commande qui reste active
        $this->assertSame('occupied', $this->table->fresh()->status);
        $this->assertSame($secondOrder->id, $this->table->fresh()->current_order_id);
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
}
