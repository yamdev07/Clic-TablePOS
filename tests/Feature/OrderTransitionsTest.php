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
}
