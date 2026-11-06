<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardDataTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private User $waiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();

        $this->waiter = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => 'waiter',
        ]);
    }

    private function freeTable(): Table
    {
        return Table::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => 'free',
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
}
