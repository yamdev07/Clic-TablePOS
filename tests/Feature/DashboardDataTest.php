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

class DashboardDataTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private User $waiter;

    private User $kitchen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();

        $this->waiter = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'waiter',
        ]);

        $this->kitchen = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'kitchen',
        ]);
    }

    private function freeTable(): Table
    {
        return Table::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => 'free',
        ]);
    }

    private function pendingItem(): OrderItem
    {
        $order = Order::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'table_id' => $this->freeTable()->id,
            'user_id' => $this->waiter->id,
            'status' => 'in_progress',
        ]);

        $menuItem = MenuItem::factory()->create(['restaurant_id' => $this->restaurant->id]);

        return OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => $menuItem->id,
            'item_name' => 'Plat',
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
            'kitchen_status' => 'pending',
        ]);
    }

    // ── Nombre de couverts ──

    public function test_order_stores_guest_count(): void
    {
        Sanctum::actingAs($this->waiter);
        $table = $this->freeTable();

        $this->postJson('/api/orders', ['table_id' => $table->id, 'guest_count' => 4])
            ->assertCreated();

        $this->assertDatabaseHas('orders', [
            'restaurant_id' => $this->restaurant->id,
            'guest_count' => 4,
        ]);
    }

    public function test_guest_count_is_optional(): void
    {
        Sanctum::actingAs($this->waiter);
        $table = $this->freeTable();

        $this->postJson('/api/orders', ['table_id' => $table->id])->assertCreated();

        $order = Order::where('restaurant_id', $this->restaurant->id)->firstOrFail();
        $this->assertNull($order->guest_count);
    }

    public function test_guest_count_must_be_between_1_and_99(): void
    {
        Sanctum::actingAs($this->waiter);
        $table = $this->freeTable();

        foreach ([0, -1, 100, 'abc'] as $invalid) {
            $this->postJson('/api/orders', ['table_id' => $table->id, 'guest_count' => $invalid])
                ->assertStatus(422)
                ->assertJsonValidationErrors('guest_count');
        }

        $this->assertSame(0, Order::count());
    }

    // ── Heure à laquelle un plat est prêt ──

    public function test_marking_ready_records_ready_at(): void
    {
        Sanctum::actingAs($this->kitchen);
        $item = $this->pendingItem();

        $this->patchJson("/api/kitchen/items/{$item->id}/ready")->assertOk();

        $item = $item->fresh();
        $this->assertSame('ready', $item->kitchen_status);
        $this->assertNotNull($item->ready_at);
    }

    public function test_ready_at_keeps_the_first_time_when_marked_twice(): void
    {
        Sanctum::actingAs($this->kitchen);
        $item = $this->pendingItem();

        $this->patchJson("/api/kitchen/items/{$item->id}/ready")->assertOk();
        $first = $item->fresh()->ready_at->timestamp;

        $this->travel(5)->minutes();
        $this->patchJson("/api/kitchen/items/{$item->id}/ready")->assertOk();

        $this->assertSame($first, $item->fresh()->ready_at->timestamp);
    }

    public function test_starting_cooking_does_not_set_ready_at(): void
    {
        Sanctum::actingAs($this->kitchen);
        $item = $this->pendingItem();

        $this->patchJson("/api/kitchen/items/{$item->id}/cooking")->assertOk();

        $this->assertNull($item->fresh()->ready_at);
    }
}
