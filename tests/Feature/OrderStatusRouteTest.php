<?php

namespace Tests\Feature;

use App\Events\NewOrderReceived;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderStatusRouteTest extends TestCase
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
            'status' => 'free',
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

    public function test_no_role_can_mark_an_order_ready_through_the_status_route(): void
    {
        foreach (['admin', 'manager', 'waiter'] as $role) {
            $this->actingAsRole($role);
            $order = $this->order('in_progress', ['cooking']);

            $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'ready'])
                ->assertStatus(422)
                ->assertJson(['success' => false]);

            $this->assertSame('in_progress', $order->fresh()->status, "rôle {$role}");
            $this->assertSame('cooking', $order->items()->first()->kitchen_status, "rôle {$role}");
        }
    }

    public function test_status_route_sends_an_open_order_to_the_kitchen_like_send_to_kitchen(): void
    {
        Event::fake([NewOrderReceived::class]);
        $this->actingAsRole('waiter');
        $order = $this->order('open', ['pending']);

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'in_progress'])->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('in_progress', $fresh->status);
        $this->assertNotNull($fresh->confirmed_at);
        $this->assertSame('occupied', $this->table->fresh()->status);
        $this->assertSame($order->id, $this->table->fresh()->current_order_id);
        Event::assertDispatchedTimes(NewOrderReceived::class, 1);
    }
}
